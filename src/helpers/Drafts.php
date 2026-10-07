<?php

namespace zemis\datebook\helpers;

use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use InvalidArgumentException;

/**
 * Reads the draft attributes that Craft attaches to drafts as a behavior.
 */
final class Drafts
{
    public static function behavior(Entry $draft): DraftBehavior
    {
        $behavior = $draft->getBehavior('draft');
        if (!$behavior instanceof DraftBehavior) {
            throw new InvalidArgumentException("Entry $draft->id is not a draft.");
        }

        return $behavior;
    }

    /**
     * The draft's name, for example "Spring update".
     */
    public static function name(Entry $draft): string
    {
        return (string)self::behavior($draft)->draftName;
    }

    /**
     * The ID of the user who created the draft, if known.
     */
    public static function creatorId(Entry $draft): ?int
    {
        $creatorId = self::behavior($draft)->creatorId;

        return $creatorId !== null ? (int)$creatorId : null;
    }
}
