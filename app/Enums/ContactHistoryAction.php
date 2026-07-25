<?php

namespace App\Enums;

enum ContactHistoryAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case TagAdded = 'tag_added';
    case TagRemoved = 'tag_removed';
    case MarkedDoNotContact = 'marked_do_not_contact';
    case UnmarkedDoNotContact = 'unmarked_do_not_contact';
    case Imported = 'imported';
    case Merged = 'merged';
    case Deleted = 'deleted';
    case Restored = 'restored';
}
