<?php

namespace PhpTelegramBot\Core\Entities\PaidMedia;

use PhpTelegramBot\Core\Contracts\Factory;
use PhpTelegramBot\Core\Entities\Entity;

/**
 * @method string getType() Type of the paid media
 */
abstract class PaidMedia extends Entity implements Factory
{
    public const TYPE_PREVIEW = 'preview';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_VIDEO = 'video';

    public static function make(array $data): static
    {
        return match ($data['type']) {
            self::TYPE_PREVIEW => new PaidMediaPreview($data),
            self::TYPE_PHOTO   => new PaidMediaPhoto($data),
            self::TYPE_VIDEO   => new PaidMediaVideo($data),
        };
    }
}
