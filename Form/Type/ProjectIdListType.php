<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Form\Type;

use App\Configuration\SystemConfiguration;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use KimaiPlugin\McpServerBundle\Support\Value;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A multi-select of projects stored as one comma-separated string, so a single configuration row holds the list.
 * A ChoiceType rather than an EntityType, whose transformer expects entities. Visible projects are offered, plus
 * the ones the setting already holds, or saving would drop an archived absence project without anyone noticing.
 *
 * @extends AbstractType<string|null>
 */
final class ProjectIdListType extends AbstractType
{
    public const OPTION_SETTING_KEY = 'setting_key';

    /** @var array<string, array<int|string, array{label: string, group: string}>> PHP turns the numeric ID keys into integers */
    private array $entries = [];

    public function __construct(
        private readonly SystemConfiguration $configuration,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = \is_array($options['choices']) ? $options['choices'] : [];
        $known = array_fill_keys(array_map(static fn (mixed $id): string => Value::string($id), $choices), true);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn ($stored): array => array_values(array_filter(
                array_map(static fn (int $id): string => (string) $id, McpSettings::parseIds($stored)),
                static fn (string $id): bool => isset($known[$id])
            )),
            // null removes the configuration row
            static fn ($ids): ?string => \is_array($ids) && $ids !== [] ? implode(',', array_map(static fn (mixed $id): string => Value::string($id), $ids)) : null
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined([self::OPTION_SETTING_KEY]);
        $resolver->setAllowedTypes(self::OPTION_SETTING_KEY, ['string', 'null']);
        $resolver->setDefaults([
            self::OPTION_SETTING_KEY => null,
            'multiple' => true,
            'choice_translation_domain' => false,
            'choices' => fn (Options $options): array => array_map(static fn (int|string $id): string => (string) $id, array_keys($this->entries(Value::stringOrNull($options[self::OPTION_SETTING_KEY])))),
            'choice_label' => function (Options $options): callable {
                $entries = $this->entries(Value::stringOrNull($options[self::OPTION_SETTING_KEY]));

                return static fn (string $id): string => $entries[$id]['label'] ?? $id;
            },
            'group_by' => function (Options $options): callable {
                $entries = $this->entries(Value::stringOrNull($options[self::OPTION_SETTING_KEY]));

                return static fn (string $id): ?string => $entries[$id]['group'] ?? null;
            },
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * @return array<int|string, array{label: string, group: string}>
     */
    private function entries(?string $settingKey): array
    {
        $cacheKey = $settingKey ?? '';
        if (isset($this->entries[$cacheKey])) {
            return $this->entries[$cacheKey];
        }

        $stored = $settingKey === null ? [] : McpSettings::parseIds($this->configuration->find($settingKey));

        /** @var list<Project> $visible */
        $visible = $this->projects->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.customer', 'c')
            ->andWhere('p.visible = true')
            ->andWhere('c.visible = true')
            ->getQuery()
            ->getResult();

        $entries = [];
        foreach (array_merge($visible, $stored === [] ? [] : $this->projects->findByIds($stored)) as $project) {
            $customer = (string) $project->getCustomer()?->getName();
            $entries[(string) $project->getId()] = ['label' => $customer . ' › ' . $project->getName(), 'group' => $customer];
        }

        uasort($entries, static fn (array $a, array $b): int => strnatcasecmp($a['group'], $b['group']) ?: strnatcasecmp($a['label'], $b['label']));

        return $this->entries[$cacheKey] = $entries;
    }
}
