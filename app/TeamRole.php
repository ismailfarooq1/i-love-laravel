<?php

namespace App;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Reviewer = 'reviewer';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }

    public function canReview(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Reviewer], true);
    }

    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
