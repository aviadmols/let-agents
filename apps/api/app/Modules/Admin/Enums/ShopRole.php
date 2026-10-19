<?php

namespace App\Modules\Admin\Enums;

/**
 * What a person may do in a shop's panel. An owner does everything, the plan and the people included;
 * the team answers shoppers and edits knowledge and tags; a viewer reads analytics and reports and
 * changes nothing.
 */
enum ShopRole: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __("admin::users.roles.{$this->value}");
    }

    /** What the role may do, in the shop manager's words. */
    public function description(): string
    {
        return __("admin::users.role_text.{$this->value}");
    }

    /** Whether the role may change anything in the shop (settings, answers, knowledge). */
    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }

    /** Whether the role may invite and remove people and change the connection. */
    public function managesAccount(): bool
    {
        return $this === self::Owner;
    }
}
