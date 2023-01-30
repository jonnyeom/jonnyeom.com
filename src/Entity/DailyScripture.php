<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use DateTime;

/** A Daily Scripture Entity */
#[ApiResource(
    collectionOperations: [],
    itemOperations: ['GET'],
)]
class DailyScripture
{
    public function __construct(#[ApiProperty(identifier: true)]
    private readonly DateTime $date, private readonly string $scripture, private readonly string $body,)
    {
    }

    public function getDate(): string
    {
        return $this->date->format('m-d-Y');
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getScripture(): string
    {
        return $this->scripture;
    }
}
