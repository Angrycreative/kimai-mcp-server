<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

/**
 * The arguments the customer and project tools take, shared by creating and updating: each one's schema, and the
 * field of Kimai's edit form it goes to (see EntityForms).
 */
final class EntityArguments
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function projectFields(): array
    {
        return [
            'customer_id' => ['customer', EntityForms::CUSTOMER],
            'name' => ['name', EntityForms::TEXT],
            'number' => ['number', EntityForms::TEXT],
            'comment' => ['comment', EntityForms::TEXT],
            'order_number' => ['orderNumber', EntityForms::TEXT],
            'order_date' => ['orderDate', EntityForms::DATE],
            'start' => ['start', EntityForms::DATE],
            'end' => ['end', EntityForms::DATE],
            'invoice_text' => ['invoiceText', EntityForms::TEXT],
            'billable' => ['billable', EntityForms::BOOL],
            'global_activities' => ['globalActivities', EntityForms::BOOL],
            ...self::budgetFields(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function projectSchema(bool $update): array
    {
        return [
            'customer_id' => Schema::id($update ? 'Move the project to this customer (ID from find_customers). Its logged time goes with it' : 'The customer it is for (ID from find_customers)'),
            'name' => ['type' => 'string', 'minLength' => 1, 'description' => $update ? 'The new name' : 'The project name'],
            'number' => self::text('Project number, at most 10 characters' . ($update ? '' : '. Left out, Kimai numbers it as configured')),
            'comment' => self::text('Description'),
            'order_number' => self::text('The customer\'s order or purchase order number'),
            'order_date' => self::date('Order date'),
            'start' => self::date('First day time can be logged on it'),
            'end' => self::date('Last day time can be logged on it'),
            'invoice_text' => self::text('Text shown for it on invoices'),
            'billable' => ['type' => 'boolean', 'description' => 'Whether time on it is billable by default' . ($update ? '' : ' (default: yes)')],
            'global_activities' => ['type' => 'boolean', 'description' => 'Whether global activities can be used on it' . ($update ? '' : ' (default: yes)')],
            ...self::budgetSchema(),
            'custom_fields' => Schema::customFields('Custom field values by field name, such as the company entity'),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function customerFields(): array
    {
        return [
            'name' => ['name', EntityForms::TEXT],
            'number' => ['number', EntityForms::TEXT],
            'comment' => ['comment', EntityForms::TEXT],
            'company' => ['company', EntityForms::TEXT],
            'vat_id' => ['vatId', EntityForms::TEXT],
            'contact' => ['contact', EntityForms::TEXT],
            'address' => ['addressLine', EntityForms::ADDRESS],
            'postcode' => ['postCode', EntityForms::TEXT],
            'city' => ['city', EntityForms::TEXT],
            'country' => ['country', EntityForms::TEXT],
            'currency' => ['currency', EntityForms::TEXT],
            'timezone' => ['timezone', EntityForms::TEXT],
            'language' => ['language', EntityForms::TEXT],
            'email' => ['email', EntityForms::TEXT],
            'phone' => ['phone', EntityForms::TEXT],
            'mobile' => ['mobile', EntityForms::TEXT],
            'homepage' => ['homepage', EntityForms::TEXT],
            'invoice_text' => ['invoiceText', EntityForms::TEXT],
            'invoice_email' => ['invoiceEmail', EntityForms::TEXT],
            'buyer_reference' => ['buyerReference', EntityForms::TEXT],
            'billable' => ['billable', EntityForms::BOOL],
            ...self::budgetFields(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function customerSchema(bool $update): array
    {
        $default = $update ? '' : '. Left out, Kimai\'s default for new customers';

        return [
            'name' => ['type' => 'string', 'minLength' => 1, 'description' => $update ? 'The new name' : 'The customer name'],
            'number' => self::text('Customer number' . ($update ? '' : '. Left out, Kimai numbers it as configured')),
            'comment' => self::text('Description'),
            'company' => self::text('Legal company name'),
            'vat_id' => self::text('VAT number'),
            'contact' => self::text('Contact person'),
            'address' => self::text('Street address, up to three lines separated by line breaks (postcode and city go separately)'),
            'postcode' => self::text('Postcode'),
            'city' => self::text('City'),
            'country' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$', 'description' => 'Country, ISO 3166 code such as SE or GB' . $default],
            'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'description' => 'Currency, ISO 4217 code such as SEK or EUR' . $default],
            'timezone' => ['type' => 'string', 'minLength' => 1, 'description' => 'Timezone, such as Europe/Stockholm' . $default],
            'language' => ['type' => 'string', 'minLength' => 1, 'description' => 'Language for invoices, such as sv or en' . $default],
            'email' => self::text('Email address'),
            'phone' => self::text('Phone number'),
            'mobile' => self::text('Mobile number'),
            'homepage' => self::text('Website'),
            'invoice_text' => self::text('Text shown on its invoices'),
            'invoice_email' => self::text('Address invoices are sent to'),
            'buyer_reference' => self::text('Buyer reference for e-invoices'),
            'billable' => ['type' => 'boolean', 'description' => 'Whether time for it is billable by default' . ($update ? '' : ' (default: yes)')],
            ...self::budgetSchema(),
            'custom_fields' => Schema::customFields('Custom field values by field name, such as the YouTrack key or billing references'),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    private static function budgetFields(): array
    {
        return [
            'budget' => ['budget', EntityForms::MONEY],
            'time_budget_hours' => ['timeBudget', EntityForms::HOURS],
            'budget_type' => ['budgetType', EntityForms::BUDGET_TYPE],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function budgetSchema(): array
    {
        return [
            'budget' => ['type' => 'number', 'minimum' => 0, 'description' => 'Money budget in its customer\'s currency; 0 for none. Needs Kimai\'s budget permission'],
            'time_budget_hours' => ['type' => 'number', 'minimum' => 0, 'description' => 'Time budget in hours; 0 for none. Needs Kimai\'s time budget permission'],
            'budget_type' => ['type' => 'string', 'enum' => ['total', 'month'], 'description' => 'Whether the budgets are for the whole lifetime (total) or for each month (month)'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $description): array
    {
        return ['type' => 'string', 'description' => $description . '. An empty string clears it'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function date(string $description): array
    {
        return ['type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$', 'description' => $description . ' (YYYY-MM-DD). An empty string clears it'];
    }
}
