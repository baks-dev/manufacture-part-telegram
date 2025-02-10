<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\TelegramManufacturePart;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Manufacture\Part\Entity\Invariable\ManufacturePartInvariable;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Telegram\Repository\ManufacturePartFixed\ManufacturePartFixedInterface;
use BaksDev\Manufacture\Part\Type\Id\ManufacturePartUid;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionDTO;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionHandler;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TelegramManufacturePartDone
{
    public function __construct(
        #[Target('manufacturePartTelegramLogger')] private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        private TelegramSendMessages $telegramSendMessage,
        private ManufacturePartActionHandler $ManufacturePartActionHandler,
        private Security $security,
        private ManufacturePartFixedInterface $manufacturePartFixed,
    ) {}

    /**
     * Выполняем действие сотрудника
     */
    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestIdentifier $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(
            !$TelegramRequest instanceof TelegramRequestCallback ||
            $TelegramRequest->getCall() !== 'manufacture-part-done' ||
            !$this->security->isGranted('ROLE_USER')
        )
        {
            return;
        }


        /**
         * Получаем заявку на производство по идентификатору партии
         */
        $ManufacturePartUid = new ManufacturePartUid($TelegramRequest->getIdentifier());

        /** @var ManufacturePart $ManufacturePart */
        $ManufacturePart = $this->entityManager
            ->getRepository(ManufacturePart::class)
            ->find($ManufacturePartUid);

        if(!$ManufacturePart)
        {
            return;
        }


        /**
         * Проверяем, что профиль пользователя чата активный
         */
        $UserProfileUid = $this->activeProfileByAccountTelegram
            ->findByChat($TelegramRequest->getChatId());

        if(!$UserProfileUid)
        {
            $this->logger->warning('Активный профиль пользователя не найден', [
                __FILE__.''.__LINE__,
                'chat' => $TelegramRequest->getChatId()
            ]);

            return;
        }


        $this->telegramSendMessage->chanel($TelegramRequest->getChatId());


        /**
         * TODO: Проверяем, что профиль пользователя чата соответствует правилам доступа
         */


        /** Снимаем фиксацию с производственной партии за сотрудником */
        $fixedManufacturePart = $this->manufacturePartFixed->cancel($ManufacturePart->getEvent(), $UserProfileUid);

        /** @var ManufacturePartInvariable $ManufacturePartInvariable */
        $ManufacturePartInvariable = $this->entityManager
            ->getRepository(ManufacturePartInvariable::class)
            ->find($ManufacturePartUid);

        if(!$fixedManufacturePart)
        {
            /* Получаем профиль пользователя зафиксировавшего партию */
            $fixedUserProfile = $this->manufacturePartFixed->findUserProfile($ManufacturePart->getEvent());

            if(!$fixedUserProfile || empty($fixedUserProfile['profile_username']))
            {
                /** Отправляем сообщение с требованием QR  */
                $caption = '<b>Производственная партия:</b>';
                $caption .= "\n";
                $caption .= 'Вышлите QR-код, либо его идентификатор:';

                $this->telegramSendMessage
                    ->delete([$TelegramRequest->getId()])
                    ->message($caption)
                    ->send(false);

                return;
            }

            /** Если пользователь НЕ является фиксатором - отправляем сообщение о фиксации */
            if(false === $UserProfileUid->equals($fixedUserProfile['profile_id']))
            {
                /** Отправляем сообщение фиксации производственной партии  */
                $caption = '<b>Производственная партия:</b>';
                $caption .= "\n";
                $caption .= "\n";
                $caption .= sprintf('Номер: <b>%s</b>', $ManufacturePartInvariable->getNumber());
                $caption .= "\n";
                $caption .= sprintf('Выполняется пользователем: <b>%s</b>', $fixedUserProfile['profile_username']);

                $this->telegramSendMessage
                    ->delete([$TelegramRequest->getId()])
                    ->message($caption)
                    ->send(false);

                return;
            }
        }


        /**
         * Получаем активное рабочее состояние производственной партии
         */

        $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
            ->findNextWorkingByManufacturePart($ManufacturePart->getId());

        if(!$UsersTableActionsWorkingUid)
        {
            /** Отправляем сообщение с требованием QR  */
            $caption = '<b>Производственная партия:</b>';
            $caption .= "\n";
            $caption .= 'Вышлите QR-код, либо его идентификатор:';

            $this->telegramSendMessage
                ->delete([$TelegramRequest->getId()])
                ->message($caption)
                ->send(false);

            return;
        }


        /**
         * Делаем отметку о выполнении этапа производства
         */

        $ManufacturePartActionDTO = new ManufacturePartActionDTO($ManufacturePart->getEvent());

        $ManufacturePartActionDTO
            ->getWorking()
            ->setWorking($UsersTableActionsWorkingUid)
            ->setProfile($UserProfileUid);

        $ManufacturePartHandler = $this->ManufacturePartActionHandler->handle($ManufacturePartActionDTO);

        if(!$ManufacturePartHandler instanceof ManufacturePart)
        {
            throw new DomainException(sprintf('Ошибка %s при обновлении этапа производства', $ManufacturePartHandler));
        }

        /**
         * Отправляем уведомление пользователю о выполненном им этапе
         */

        $messageHandler = '<b>Выполненный этап производственной партии:</b>';
        $messageHandler .= PHP_EOL;
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf('Номер: <b>%s</b>', $ManufacturePartInvariable->getNumber()); // номер партии
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf('Дата: <b>%s</b>', new DateTimeImmutable()->format('d.m.Y H:i')); // Дата выполненного этапа
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf("%s: <b>%s шт.</b>", $UsersTableActionsWorkingUid->getAttr(), $ManufacturePartInvariable->getQuantity()); // Этап производства

        /** Отправляем сообщение об успешном выполнении этапа */
        $this
            ->telegramSendMessage
            ->delete([$TelegramRequest->getId()])
            ->message($messageHandler)
            ->send();
    }
}