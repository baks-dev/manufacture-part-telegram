<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Manufacture\Part\Telegram\Repository\ManufacturePartFixed;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Manufacture\Part\Entity\Event\ManufacturePartEvent;
use BaksDev\Manufacture\Part\Type\Event\ManufacturePartEventUid;
use BaksDev\Users\Profile\UserProfile\Entity\Personal\UserProfilePersonal;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;

final class ManufacturePartFixedRepository implements ManufacturePartFixedInterface
{
    private DBALQueryBuilder $DBALQueryBuilder;

    public function __construct(
        DBALQueryBuilder $DBALQueryBuilder,
    )
    {
        $this->DBALQueryBuilder = $DBALQueryBuilder;
    }

    /**
     * Фиксирует производственный процесс за сотрудником
     */
    public function fixed(ManufacturePartEventUid $event, UserProfileUid $profile): int|string
    {
        $dbal = $this->DBALQueryBuilder->createQueryBuilder(self::class);

        $dbal->update(ManufacturePartEvent::class);

        $dbal
            ->set('fixed', ':fixed')
            ->setParameter('fixed', $profile, UserProfileUid::TYPE);

        $dbal
            ->where('id = :event')
            ->setParameter('event', $event, ManufacturePartEventUid::TYPE);

        $dbal->andWhere('fixed IS NULL');

        return $dbal->executeStatement();
    }


    /**
     * Снимает фиксацию с производственного процесса
     */
    public function cancel(ManufacturePartEventUid $event, UserProfileUid $profile): int|string
    {
        $dbal = $this->DBALQueryBuilder->createQueryBuilder(self::class);

        $dbal->update(ManufacturePartEvent::class);

        $dbal
            ->set('fixed', ':fixed')
            ->setParameter('fixed', null);

        $dbal
            ->where('id = :event')
            ->setParameter('event', $event, ManufacturePartEventUid::TYPE);

        $dbal
            ->andWhere('fixed = :profile')
            ->setParameter('profile', $profile, UserProfileUid::TYPE);

        return $dbal->executeStatement();
    }


    /** Возвращает пользователя, зафиксировавший производственный процесс */
    public function findUserProfile(ManufacturePartEventUid $event): array|false
    {

        $dbal = $this->DBALQueryBuilder->createQueryBuilder(self::class);

        $dbal

            ->from(ManufacturePartEvent::class, 'event')
            ->where('event.id = :event')
            ->setParameter('event', $event, ManufacturePartEventUid::TYPE);

        $dbal
            ->addSelect('profile.id AS profile_id')
            ->leftJoin(
            'event',
                UserProfile::class,
                'profile',
                'profile.id = event.fixed'
            );

        $dbal
            ->addSelect('profile_personal.username AS profile_username')
            ->leftJoin(
                'profile',
                UserProfilePersonal::class,
                'profile_personal',
                'profile_personal.event = profile.event'
            );

        return $dbal->enableCache('manufacture-part-telegram', 60)->fetchAssociative();

    }

}