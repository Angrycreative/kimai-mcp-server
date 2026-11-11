<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\User;
use App\Repository\Query\UserQuery;
use App\Repository\UserRepository;
use App\Utils\SearchTerm;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;

final class FindUsersTool extends AbstractReadTool
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function getName(): string
    {
        return 'find_users';
    }

    public function getDescription(): string
    {
        return 'Finds people by name, username or email and returns their IDs for the other tools, with their '
            . 'title, teams and whether their account is active. Only people this user may see in Kimai; system '
            . 'accounts are left out.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'query' => ['type' => 'string', 'description' => 'Words to find in the name, username, email, title or account number. Leave out to list everyone'],
            'visibility' => [
                'type' => 'string',
                'enum' => ['visible', 'hidden', 'both'],
                'description' => 'Active accounts, deactivated ones, or both. Default: visible (active)',
            ],
            'limit' => Schema::limit(),
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_user'];
    }

    public function call(array $arguments, User $user): array
    {
        $limit = Args::limit($arguments);

        $query = new UserQuery();
        $query->setCurrentUser($user);
        $query->setVisibility($this->visibility($arguments));
        $query->setSystemAccount(false);
        $query->setOrderBy('alias');
        $query->setPageSize($limit);
        $query->setPage(1);
        if (($term = Args::string($arguments, 'query')) !== null) {
            $query->setSearchTerm(new SearchTerm($term));
        }

        $pager = $this->users->getPagerfantaForQuery($query);

        $items = [];
        /** @var User $person */
        foreach ($pager->getCurrentPageResults() as $person) {
            $items[] = [
                'id' => $person->getId(),
                'name' => $person->getDisplayName(),
                'username' => $person->getUserIdentifier(),
                'email' => $person->getEmail(),
                'title' => $person->getTitle(),
                'active' => $person->isEnabled(),
                'teams' => array_map(static fn ($team): array => [
                    'id' => $team->getId(),
                    'name' => $team->getName(),
                    'lead' => $person->isTeamleadOf($team),
                ], $person->getTeams()),
            ];
        }

        return $this->listResult('users', $items, $pager->getNbResults(), $limit);
    }
}
