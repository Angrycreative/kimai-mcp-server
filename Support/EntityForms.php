<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Entity\Customer;
use App\Entity\EntityWithMetaFields;
use App\Entity\Project;
use App\Entity\User;
use App\Form\CustomerEditForm;
use App\Form\ProjectEditForm;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Creates and changes customers and projects through Kimai's own edit forms, the ones its edit pages use. What the
 * tools may set is therefore what the same person may set on those pages: the money budget only with the "budget"
 * permission on the entity, the time budget only with "time", a custom field that is locked for them (the company
 * entity, say) stays locked, a project can only go to a customer they can see, and Kimai's validation applies.
 *
 * A tool describes its arguments as fields: argument name => [form field, kind]. The kind decides how a value is
 * handed to the form and how it reads back.
 */
final class EntityForms
{
    public const TEXT = 'text';
    public const BOOL = 'bool';
    public const DATE = 'date';
    public const MONEY = 'money';
    public const HOURS = 'hours';
    public const BUDGET_TYPE = 'budget_type';
    public const CUSTOMER = 'customer';
    /** Up to three lines, one per line of the customer's address */
    public const ADDRESS = 'address';

    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly EntityManagerInterface $entities,
    ) {
    }

    /**
     * Lets go of a customer or project a form was submitted to without saving it (a preview, or a change Kimai
     * refused), with its custom fields: the next read loads it afresh, and nothing saved later in the same request
     * takes the unsaved values along.
     */
    public function forget(object $entity): void
    {
        if ($entity instanceof EntityWithMetaFields) {
            foreach ($entity->getMetaFields() as $meta) {
                if ($this->entities->contains($meta)) {
                    $this->entities->detach($meta);
                }
            }
        }
        if ($this->entities->contains($entity)) {
            $this->entities->detach($entity);
        }
    }

    public function customerForm(Customer $customer): FormInterface
    {
        return $this->forms->create(CustomerEditForm::class, $customer, [
            'csrf_protection' => false,
            'include_budget' => $this->auth->isGranted('budget', $customer),
            'include_time' => $this->auth->isGranted('time', $customer),
        ]);
    }

    public function projectForm(Project $project, User $user): FormInterface
    {
        $options = [
            'csrf_protection' => false,
            'timezone' => $user->getTimezone(),
            'date_format' => 'yyyy-MM-dd',
            'include_budget' => $this->auth->isGranted('budget', $project),
            'include_time' => $this->auth->isGranted('time', $project),
        ];
        $currency = $project->getCustomer()?->getCurrency();
        if ($currency !== null) {
            $options['currency'] = $currency;
        }

        return $this->forms->create(ProjectEditForm::class, $project, $options);
    }

    /**
     * Submits the given arguments to the form, leaving everything else as it is, and refuses what Kimai would refuse.
     *
     * @param array<string, array{string, string}> $fields argument name => [form field, kind]
     * @param array<string, mixed> $args
     * @param string $entities "Customers" or "Projects", for messages
     * @throws ToolException
     */
    public function submit(FormInterface $form, array $fields, array $args, string $entities): void
    {
        /** @var array<string, mixed> $data */
        $data = [];
        foreach ($fields as $arg => [$name, $kind]) {
            if (!\array_key_exists($arg, $args) || $args[$arg] === null) {
                continue;
            }
            if ($kind === self::ADDRESS) {
                $lines = preg_split('/\R/', trim(Value::string($args[$arg]))) ?: [];
                if (\count($lines) > 3) {
                    throw new ToolException(\sprintf('%s takes at most three lines.', $arg));
                }
                foreach ([1, 2, 3] as $i) {
                    $data += $this->field($form, $name . $i, $arg, $kind, trim($lines[$i - 1] ?? ''));
                }
                continue;
            }
            $data += $this->field($form, $name, $arg, $kind, $args[$arg]);
        }

        $custom = Args::customFields($args);
        $values = [];
        if ($custom !== []) {
            $meta = $form->has('metaFields') ? $form->get('metaFields') : null;
            $entity = $form->getData();
            foreach ($custom as $name => $value) {
                $field = $entity instanceof EntityWithMetaFields ? $entity->getMetaField($name) : null;
                if ($meta === null || !$meta->has($name) || $field === null || !$field->isVisible()) {
                    throw new ToolException(\sprintf('%s have no custom field "%s". The ones there are: %s.', $entities, $name, implode(', ', $this->customFieldNames($form)) ?: 'none'));
                }
                if ($meta->get($name)->get('value')->isDisabled()) {
                    throw new ToolException(\sprintf('Kimai does not let you change the custom field "%s".', $name));
                }
                $values[$name] = ['value' => $this->customValue($meta->get($name)->get('value'), $value)];
            }
            $data['metaFields'] = $values;
        }

        $form->submit($data, false);
        if (!$form->isValid()) {
            throw new ToolException('Kimai would refuse this: ' . $this->errors($form, $fields));
        }
        $missing = $this->missingRequired($form);
        if ($missing !== []) {
            throw new ToolException(\sprintf('Kimai would refuse this: it requires the custom field%s %s. Give %s in custom_fields.', \count($missing) > 1 ? 's' : '', implode(', ', $missing), \count($missing) > 1 ? 'them' : 'it'));
        }
    }

    /**
     * Required custom fields left empty that the person can fill in. Kimai's form checks these only when they are
     * submitted, which a partial submission leaves out; its edit page always submits them, so it refuses a save
     * without them. One that is locked or hidden for the person is not submitted by the edit page either, so it saves
     * without it, and so do these tools.
     *
     * @return list<string>
     */
    private function missingRequired(FormInterface $form): array
    {
        $entity = $form->getData();
        if (!$entity instanceof EntityWithMetaFields || !$form->has('metaFields')) {
            return [];
        }
        $missing = [];
        foreach ($form->get('metaFields') as $name => $child) {
            $field = $entity->getMetaField((string) $name);
            if ($field !== null && $field->isRequired() && $field->isVisible() && !$child->get('value')->isDisabled() && \in_array($field->getValue(), [null, ''], true)) {
                $missing[] = (string) $name;
            }
        }

        return $missing;
    }

    /**
     * The entity's values as the tool's arguments name them, with its custom fields.
     *
     * @param array<string, array{string, string}> $fields
     * @return array<string, mixed>
     */
    public function describe(object $entity, array $fields, User $user): array
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $values = [];
        foreach ($fields as $arg => [$name, $kind]) {
            if ($kind === self::ADDRESS) {
                $lines = array_map(static fn (int $i): string => Value::string($accessor->getValue($entity, $name . $i)), [1, 2, 3]);
                $values[$arg] = implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')) ?: null;
                continue;
            }
            // the customer reads back as itself, with its name, rather than as the ID it was given by
            $values[$kind === self::CUSTOMER ? 'customer' : $arg] = $this->readValue($accessor->getValue($entity, $name), $kind, $user);
        }
        if ($entity instanceof EntityWithMetaFields) {
            $values['custom_fields'] = Format::metaFields($entity);
        }

        return $values;
    }

    /**
     * The description without what is empty, for a new customer or project.
     *
     * @param array<string, array{string, string}> $fields
     * @return array<string, mixed>
     */
    public function describeSet(object $entity, array $fields, User $user): array
    {
        return array_filter($this->describe($entity, $fields, $user), static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * What differs between two descriptions, as from and to.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if ($key === 'custom_fields' && \is_array($value)) {
                $old = \is_array($before[$key] ?? null) ? $before[$key] : [];
                foreach (array_unique([...array_keys($old), ...array_keys($value)]) as $name) {
                    if (($old[$name] ?? null) !== ($value[$name] ?? null)) {
                        $changes['custom_fields.' . $name] = ['from' => $old[$name] ?? null, 'to' => $value[$name] ?? null];
                    }
                }
                continue;
            }
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * One form field's submitted value, if the person may set that field.
     *
     * @return array<string, string>
     * @throws ToolException
     */
    private function field(FormInterface $form, string $name, string $arg, string $kind, mixed $value): array
    {
        if (!$form->has($name)) {
            throw new ToolException($this->notOffered($arg, $kind));
        }
        if ($form->get($name)->isDisabled()) {
            throw new ToolException(\sprintf('Kimai does not let you change %s here.', $arg));
        }
        $value = $this->formValue($value, $kind);
        // Kimai's text fields trim what they are given, so only spaces is empty too
        if (trim($value) === '' && !$this->mayBeEmpty($form->getData(), $name)) {
            // an empty text reaches the entity as null, which a setter such as setName() or setCountry() does not
            // take: the form would throw rather than report it
            throw new ToolException(\sprintf('Kimai would refuse this: %s cannot be empty.', $arg));
        }

        return [$name => $value];
    }

    private function mayBeEmpty(mixed $entity, string $name): bool
    {
        $setter = 'set' . ucfirst($name);
        if (!\is_object($entity) || !method_exists($entity, $setter)) {
            return true;
        }
        $parameter = (new \ReflectionMethod($entity, $setter))->getParameters()[0] ?? null;

        return $parameter === null || $parameter->allowsNull();
    }

    /**
     * @return list<string>
     */
    private function customFieldNames(FormInterface $form): array
    {
        if (!$form->has('metaFields')) {
            return [];
        }
        $names = [];
        $entity = $form->getData();
        foreach ($form->get('metaFields') as $name => $child) {
            $field = $entity instanceof EntityWithMetaFields ? $entity->getMetaField((string) $name) : null;
            if ($field !== null && $field->isVisible() && !$child->get('value')->isDisabled()) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    private function notOffered(string $arg, string $kind): string
    {
        return match ($kind) {
            self::MONEY => 'Setting a money budget needs Kimai\'s budget permission for it, which you do not have.',
            self::HOURS => 'Setting a time budget needs Kimai\'s time budget permission for it, which you do not have.',
            self::BUDGET_TYPE => 'Setting the budget type needs Kimai\'s budget or time budget permission for it, which you do not have.',
            default => \sprintf('Kimai does not let you set %s here.', $arg),
        };
    }

    /**
     * A custom field's submitted value. A date field reads dates in the person's locale format, like Kimai's own
     * date fields; the tools take them as YYYY-MM-DD, so a date is written out the way that field reads it.
     */
    private function customValue(FormInterface $field, mixed $value): string
    {
        $text = $this->formValue($value, \is_bool($value) ? self::BOOL : self::TEXT);
        $format = match (true) {
            $this->isOfType($field, DateTimeType::class) => 'Y-m-d H:i',
            $this->isOfType($field, DateType::class) => 'Y-m-d',
            default => null,
        };
        if ($format === null) {
            return $text;
        }
        $timezone = $field->getConfig()->getOption('model_timezone');
        $date = \DateTime::createFromFormat('!' . $format, $text, new \DateTimeZone(\is_string($timezone) ? $timezone : date_default_timezone_get()));
        // anything else, an impossible date included, goes to the form as given, which refuses it by name
        if ($date === false || $date->format($format) !== $text) {
            return $text;
        }
        $view = $date;
        foreach ($field->getConfig()->getViewTransformers() as $transformer) {
            $view = $transformer->transform($view);
        }

        return \is_string($view) ? $view : $text;
    }

    /**
     * @param class-string $type
     */
    private function isOfType(FormInterface $field, string $type): bool
    {
        for ($resolved = $field->getConfig()->getType(); $resolved !== null; $resolved = $resolved->getParent()) {
            if ($resolved->getInnerType() instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function formValue(mixed $value, string $kind): string
    {
        return match ($kind) {
            self::BOOL => $value === true ? '1' : '0',
            // the forms parse in the person's locale; a plain decimal point is read correctly in every locale we run
            self::MONEY => number_format(Value::float($value), 2, '.', ''),
            self::HOURS => (string) round(Value::float($value), 4),
            self::BUDGET_TYPE => $value === 'month' ? 'month' : '',
            default => \is_scalar($value) ? (string) $value : '',
        };
    }

    private function readValue(mixed $value, string $kind, User $user): mixed
    {
        return match ($kind) {
            self::DATE => $value instanceof \DateTimeInterface ? Format::date(\DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone($user->getTimezone()))) : null,
            self::MONEY => Format::money(Value::float($value)),
            self::HOURS => Format::hours(Value::int($value)),
            self::BUDGET_TYPE => $value === 'month' ? 'month' : 'total',
            self::CUSTOMER => $value instanceof Customer ? ['id' => $value->getId(), 'name' => $value->getName()] : null,
            self::BOOL => (bool) $value,
            default => $value === '' ? null : $value,
        };
    }

    /**
     * Kimai's messages, each named by the argument it is about.
     *
     * @param array<string, array{string, string}> $fields
     */
    private function errors(FormInterface $form, array $fields): string
    {
        $arguments = [];
        foreach ($fields as $arg => [$name, $kind]) {
            if ($kind === self::ADDRESS) {
                foreach ([1, 2, 3] as $i) {
                    $arguments[$name . $i] = $arg;
                }
                continue;
            }
            $arguments[$name] = $arg;
        }
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            if (!$error instanceof FormError) {
                continue;
            }
            $path = [];
            for ($origin = $error->getOrigin(); $origin !== null && $origin->getParent() !== null; $origin = $origin->getParent()) {
                array_unshift($path, $origin->getName());
            }
            $label = match (true) {
                ($path[0] ?? null) === 'metaFields' && isset($path[1]) => \sprintf('custom field "%s"', $path[1]),
                isset($path[0]) => $arguments[$path[0]] ?? $path[0],
                default => null,
            };
            $message = ($label !== null ? $label . ': ' : '') . rtrim($error->getMessage(), '.');
            if (($path[0] ?? null) === 'customer') {
                // Kimai's "The selected choice is invalid" names no reason; these are the ones its customer list has
                $message = ($label ?? 'customer') . ': not a customer you can choose here (it has to exist, be visible and be one you may see)';
            }
            $choices = $error->getOrigin()?->getConfig()->getOption('choices');
            if (\is_array($choices) && $choices !== [] && \count($choices) <= 20) {
                $message .= \sprintf(' (one of: %s)', implode(', ', array_map(static fn (mixed $choice): string => Value::string($choice), array_values($choices))));
            }
            $messages[] = $message;
        }

        return ($messages === [] ? 'the form did not validate' : implode('; ', array_unique($messages))) . '.';
    }
}
