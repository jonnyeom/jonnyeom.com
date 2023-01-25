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
    #[ApiProperty(identifier: true)]
    private DateTime $date;

    public function __construct(DateTime $date, private string $scripture, private string $body)
    {
        $this->date = $date;
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
