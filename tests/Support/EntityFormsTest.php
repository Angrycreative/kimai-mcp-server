<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Form\Type\MetaFieldsCollectionType;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * The tools hand their arguments to Kimai's own edit forms; these stand-ins have the same kinds of fields, so what is
 * tested is how arguments reach a form and how its refusals come back, not Kimai's forms themselves.
 */
final class EntityFormsTest extends TestCase
{
    private const FIELDS = [
        'name' => ['name', EntityForms::TEXT],
        'comment' => ['comment', EntityForms::TEXT],
        'budget' => ['budget', EntityForms::MONEY],
        'time_budget_hours' => ['timeBudget', EntityForms::HOURS],
    ];

    public function testOnlyWhatIsGivenChanges(): void
    {
        $project = $this->project();
        $project->setComment('Keep me');

        $this->forms()->submit($this->form($project), self::FIELDS, ['name' => 'Renamed', 'custom_fields' => ['code' => 'AB-1']], 'Projects');

        $this->assertSame('Renamed', $project->getName());
        $this->assertSame('Keep me', $project->getComment());
        $this->assertSame('AB-1', $project->getMetaField('code')?->getValue());
    }

    public function testABudgetTheFormDoesNotOfferIsRefusedForThePermission(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('time budget needs Kimai\'s time budget permission');

        $this->forms()->submit($this->form($this->project()), self::FIELDS, ['time_budget_hours' => 10], 'Projects');
    }

    public function testABudgetTheFormOffersIsSetInAnyLocale(): void
    {
        $project = $this->project();
        $locale = \Locale::getDefault();
        \Locale::setDefault('sv');
        try {
            $this->forms()->submit($this->form($project), self::FIELDS, ['budget' => 1500.5], 'Projects');
        } finally {
            \Locale::setDefault($locale);
        }

        $this->assertSame(1500.5, $project->getBudget());
    }

    public function testALockedFieldIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai does not let you change comment here.');

        $this->forms()->submit($this->form($this->project(), lockComment: true), self::FIELDS, ['comment' => 'Changed'], 'Projects');
    }

    public function testALockedCustomFieldIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai does not let you change the custom field "entity".');

        $this->forms()->submit($this->form($this->project()), self::FIELDS, ['custom_fields' => ['entity' => 'B']], 'Projects');
    }

    public function testAnUnknownCustomFieldNamesTheOnesThePersonCanSet(): void
    {
        $this->expectException(ToolException::class);
        // "entity" is locked for this person and "hidden" is not shown in Kimai, so neither is offered
        $this->expectExceptionMessage('Projects have no custom field "nope". The ones there are: code, area.');

        $this->forms()->submit($this->form($this->project()), self::FIELDS, ['custom_fields' => ['nope' => 'x']], 'Projects');
    }

    public function testAnEmptyRequiredCustomFieldIsRefusedEvenWhenNotGiven(): void
    {
        $project = $this->project(required: true);

        try {
            $this->forms()->submit($this->form($project), self::FIELDS, ['name' => 'Renamed'], 'Projects');
            $this->fail('A project without its required custom field was accepted.');
        } catch (ToolException $e) {
            $this->assertStringContainsString('it requires the custom field area. Give it in custom_fields.', $e->getMessage());
        }

        $this->forms()->submit($this->form($project), self::FIELDS, ['custom_fields' => ['area' => 'north']], 'Projects');
        $this->assertSame('north', $project->getMetaField('area')?->getValue());
    }

    public function testKimaisRefusalsAreNamedByArgumentWithTheChoicesThereAre(): void
    {
        try {
            $this->forms()->submit($this->form($this->project()), self::FIELDS, ['comment' => 'Far too long', 'custom_fields' => ['area' => 'west']], 'Projects');
            $this->fail('An invalid project was accepted.');
        } catch (ToolException $e) {
            $this->assertSame(
                'Kimai would refuse this: comment: This value is too long. It should have 5 characters or less; custom field "area": The selected choice is invalid (one of: north, south).',
                $e->getMessage(),
            );
        }
    }

    public function testAFieldKimaiCannotStoreEmptyIsRefusedByName(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai would refuse this: name cannot be empty.');

        $this->forms()->submit($this->form($this->project()), self::FIELDS, ['name' => ''], 'Projects');
    }

    public function testANameOfOnlySpacesIsRefusedByName(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai would refuse this: name cannot be empty.');

        $this->forms()->submit($this->form($this->project()), self::FIELDS, ['name' => '   '], 'Projects');
    }

    public function testARequiredCustomFieldThePersonCannotSetDoesNotBlockTheirChanges(): void
    {
        $project = $this->project();
        $project->getMetaField('entity')?->setIsRequired(true);
        $project->getMetaField('hidden')?->setIsRequired(true);

        $this->forms()->submit($this->form($project), self::FIELDS, ['name' => 'Renamed'], 'Projects');

        $this->assertSame('Renamed', $project->getName());
    }

    public function testACustomDateIsTakenAsIsoWhateverFormatTheFieldReads(): void
    {
        $project = $this->project();
        $project->setMetaField($this->meta('ordered', DateType::class, ['widget' => 'single_text', 'html5' => false, 'format' => 'dd.MM.yyyy', 'input' => 'string']));

        $this->forms()->submit($this->form($project), self::FIELDS, ['custom_fields' => ['ordered' => '2026-11-17']], 'Projects');
        $this->assertSame('2026-11-17', $project->getMetaField('ordered')?->getValue());

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('custom field "ordered"');
        $this->forms()->submit($this->form($project), self::FIELDS, ['custom_fields' => ['ordered' => '2026-02-31']], 'Projects');
    }

    public function testForgettingLetsGoOfTheEntityAndItsCustomFields(): void
    {
        $project = $this->project();
        $entities = $this->createMock(EntityManagerInterface::class);
        $entities->method('contains')->willReturn(true);
        $detached = [];
        $entities->method('detach')->willReturnCallback(static function (object $entity) use (&$detached): void {
            $detached[] = $entity;
        });

        (new EntityForms($this->factory(), $this->createStub(AuthorizationCheckerInterface::class), $entities))->forget($project);

        $this->assertCount(5, $detached);
        $this->assertSame($project, end($detached));
    }

    public function testAnAddressIsSplitIntoKimaisThreeLines(): void
    {
        $customer = new Customer('Acme');
        $form = $this->factory()->createBuilder(FormType::class, $customer, ['data_class' => Customer::class])
            ->add('addressLine1', TextType::class)
            ->add('addressLine2', TextType::class)
            ->add('addressLine3', TextType::class)
            ->getForm();
        $fields = ['address' => ['addressLine', EntityForms::ADDRESS]];

        $this->forms()->submit($form, $fields, ['address' => "Storgatan 1\nPlan 3"], 'Customers');

        $this->assertSame('Storgatan 1', $customer->getAddressLine1());
        $this->assertSame('Plan 3', $customer->getAddressLine2());
        $this->assertNull($customer->getAddressLine3());
        $this->assertSame(['address' => "Storgatan 1\nPlan 3", 'custom_fields' => []], $this->forms()->describe($customer, $fields, TestUser::make(1)));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('address takes at most three lines.');
        $this->forms()->submit($form, $fields, ['address' => "a\nb\nc\nd"], 'Customers');
    }

    public function testChangesListOnlyWhatDiffers(): void
    {
        $changes = $this->forms()->changes(
            ['name' => 'Old', 'budget' => 10.0, 'custom_fields' => ['code' => 'A', 'gone' => 'x']],
            ['name' => 'Old', 'budget' => 20.0, 'custom_fields' => ['code' => 'A', 'new' => 'y']],
        );

        $this->assertSame([
            'budget' => ['from' => 10.0, 'to' => 20.0],
            'custom_fields.gone' => ['from' => 'x', 'to' => null],
            'custom_fields.new' => ['from' => null, 'to' => 'y'],
        ], $changes);
    }

    private function forms(): EntityForms
    {
        return new EntityForms($this->factory(), $this->createStub(AuthorizationCheckerInterface::class), $this->createStub(EntityManagerInterface::class));
    }

    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    /**
     * Like Kimai's project form for someone with the money budget permission but not the time one, and with the
     * "entity" custom field locked for them.
     */
    private function form(Project $project, bool $lockComment = false): FormInterface
    {
        return $this->factory()->createBuilder(FormType::class, $project, ['data_class' => Project::class])
            ->add('name', TextType::class, ['constraints' => [new NotBlank()]])
            ->add('comment', TextType::class, ['disabled' => $lockComment, 'constraints' => [new Length(max: 5)]])
            ->add('budget', NumberType::class)
            ->add('metaFields', MetaFieldsCollectionType::class)
            ->getForm();
    }

    private function project(bool $required = false): Project
    {
        $project = new Project();
        $project->setName('Website');
        $project->setMetaField($this->meta('code', TextType::class));
        $project->setMetaField($this->meta('entity', TextType::class, ['disabled' => true]));
        $project->setMetaField($this->meta('area', ChoiceType::class, ['choices' => ['north' => 'north', 'south' => 'south']], $required));
        $project->setMetaField($this->meta('hidden', TextType::class, visible: false));

        return $project;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function meta(string $name, string $type, array $options = [], bool $required = false, bool $visible = true): ProjectMeta
    {
        $meta = new ProjectMeta();
        $meta->setName($name);
        $meta->setType($type);
        $meta->setOptions($options);
        $meta->setIsRequired($required);
        $meta->setIsVisible($visible);

        return $meta;
    }
}
