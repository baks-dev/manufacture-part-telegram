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
use BaksDev\Manufacture\Part\Repository\AllWorkingByManufacturePart\AllWorkingByManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\ProductsByManufacturePart\ProductsByManufacturePartInterface;
use BaksDev\Manufacture\Part\Telegram\Repository\ManufacturePartFixed\ManufacturePartFixedInterface;
use BaksDev\Manufacture\Part\Type\Id\ManufacturePartUid;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class TelegramManufacturePartWorking
{
    public const string KEY = 'UfjQCCzp';

    private TelegramRequestIdentifier $request;

    public function __construct(
        #[AutowireIterator('baks.reference.choice')] private readonly iterable $reference,
        #[Target('manufacturePartTelegramLogger')] private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private readonly ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        private readonly TelegramSendMessages $telegramSendMessage,
        private readonly AllWorkingByManufacturePartInterface $allWorkingByManufacturePart,
        private readonly ProductsByManufacturePartInterface $ProductsByManufacturePart,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ManufacturePartFixedInterface $manufacturePartFixed,
    ) {}


    /**
     * Получаем состояние партии отправляем соответствующие действия
     */
    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestIdentifier $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(
            false === ($TelegramRequest instanceof TelegramRequestIdentifier) ||
            !$this->security->isGranted('ROLE_USER')
        )
        {
            return;
        }

        $this->request = $TelegramRequest;

        /**
         * Получаем заявку на производство
         */

        $ManufacturePartUid = new ManufacturePartUid($TelegramRequest->getIdentifier());

        /** @var ManufacturePart $ManufacturePart */
        $ManufacturePart = $this->entityManager
            ->getRepository(ManufacturePart::class)
            ->find($ManufacturePartUid);


        if(false === ($ManufacturePart instanceof ManufacturePart))
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


        /** @var ManufacturePartInvariable $ManufacturePartInvariable */
        $ManufacturePartInvariable = $this->entityManager
            ->getRepository(ManufacturePartInvariable::class)
            ->find($ManufacturePartUid);

        /**
         * TODO: Проверяем, что профиль пользователя чата соответствует правилам доступа
         */

        /* Получаем активное рабочее состояние производственной партии которое необходимо выполнить */
        $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
            ->findNextWorkingByManufacturePart($ManufacturePart->getId());

        if(!$UsersTableActionsWorkingUid)
        {
            /* Получаем информацию о выполненных этапах и отправляем сообщение о выполненной заявке */
            $this->partCompleted($ManufacturePart->getId());
            return;
        }

        /** Получаем этапы производства указанной производственной партии  */
        $ManufacturePartWorking = $this->allWorkingByManufacturePart
            ->fetchAllWorkingByManufacturePartAssociative($ManufacturePart->getId());

        $caption = '<b>Производственная партия:</b>';
        $caption .= "\n";
        $caption .= "\n";

        $caption .= 'Номер: <b>'.$ManufacturePartInvariable->getNumber().'</b>';
        $caption .= "\n";
        $caption .= 'Всего продукции: <b>'.$ManufacturePartInvariable->getQuantity().' шт.</b>';
        $caption .= "\n";
        $caption .= "\n";

        /** Получаем продукцию в производственной партии и присваиваем к сообщению */
        $caption = $this->captionProducts($ManufacturePart->getId(), $caption);

        /** Символ выполненного процесса  */
        $char = "\u2611\ufe0f";
        $decoded = json_decode('["'.$char.'"]');
        $done = mb_convert_encoding($decoded[0], 'UTF-8');

        /** Символ активного процесса  */
        $char = "\u25b6\ufe0f";
        $decoded = json_decode('["'.$char.'"]');
        $right = mb_convert_encoding($decoded[0], 'UTF-8');

        /** Символ НЕ выполненного процесса  */
        $char = "\u2705";
        $decoded = json_decode('["'.$char.'"]');
        $muted = mb_convert_encoding($decoded[0], 'UTF-8');

        $currentWorkingName = null;


        $caption .= '<b>Этапы производства:</b>';
        $caption .= "\n";

        /**
         * Все действия сотрудников, которые он может выполнить
         */
        foreach($ManufacturePartWorking as $working)
        {
            $icon = $currentWorkingName ? $done : $muted;

            if($UsersTableActionsWorkingUid->equals($working['working_id']))
            {
                $currentWorkingName = $working['working_name'];
                $icon = $right;
            }

            $caption .= $icon;
            $caption .= ' '.$working['working_name'];

            if($UsersTableActionsWorkingUid->equals($working['working_id']))
            {
                $caption .= ' <b>'.$ManufacturePartInvariable->getQuantity().' шт </b>';
            }

            $caption .= "\n";
        }


        $CurrentManufacturePart = current($ManufacturePartWorking);

        /* Комментарий к заявке */
        if($CurrentManufacturePart['part_comment'])
        {
            $caption .= "\n";
            $caption .= $CurrentManufacturePart['part_comment'];
            $caption .= "\n";

        }

        $caption .= "\n";
        $caption .= 'Заявка зафиксированная за Вами! Для сброса фиксации перейдите в начало меню.';

        $caption .= "\n";
        $caption .= "\n";
        $caption .= 'Если Вами был найден брак - обратитесь к ответственному за данную производственную партию.';


        /** @see TelegramManufacturePartCancel */

        $menu[] = [
            'text' => '🛑 Отмена',
            'callback_data' => sprintf('%s|%s', TelegramManufacturePartCancel::KEY, $ManufacturePartUid)
        ];

        /** @see TelegramManufacturePartDone */

        $menu[] = [
            'text' => sprintf('Выполнить "%s" все %s шт.',
                $currentWorkingName,
                $ManufacturePartInvariable->getQuantity()
            ),
            'callback_data' => sprintf('%s|%s', TelegramManufacturePartDone::KEY, $ManufacturePartUid)
        ];


        /** Отправляем сообщение */

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 1),
        ], JSON_THROW_ON_ERROR);


        $this->telegramSendMessage
            ->delete([$TelegramRequest->getId()])
            ->message($caption)
            ->markup($markup)
            ->send();
    }


    public function captionProducts(ManufacturePartUid $part, string $caption): string
    {
        $caption .= '<b>Продукция:</b>';
        $caption .= "\n";
        $products = $this->ProductsByManufacturePart
            ->forPart($part)
            ->findAll();

        foreach($products as $key => $product)
        {

            if($key >= 50)
            {
                $caption .= "\n";
                $caption .= '<b>Подробный список производственной партии более 50 позиций только в CRM!</b>';
                $caption .= "\n";
                break;
            }

            $caption .= ($key + 1).'. '.$product['product_article'].' ';

            //$caption .= $product['product_name'].' ';


            if($product['product_offer_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $product['product_offer_reference'])
                    {
                        $caption .= $this->translator->trans($product['product_offer_value'], domain: $reference->domain()).' ';
                    }
                }
            }
            else
            {
                $product['product_offer_value'] ? $caption .= $product['product_offer_value'].' ' : '';
            }

            if($product['product_variation_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $product['product_variation_reference'])
                    {
                        $caption .= $this->translator->trans($product['product_variation_value'], domain: $reference->domain()).' ';
                    }
                }
            }
            else
            {
                $product['product_variation_value'] ? $caption .= $product['product_variation_value'].' ' : '';
            }


            if($product['product_modification_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $product['product_modification_reference'])
                    {
                        $caption .= $this->translator->trans($product['product_modification_value'], domain: $reference->domain()).' ';
                    }
                }
            }
            else
            {
                $product['product_modification_value'] ? $caption .= $product['product_modification_value'].' ' : '';
            }

            $caption .= ' | <b>'.$product['product_total'].' шт.</b>';

            $caption .= "\n";
        }

        $caption .= "\n";

        return $caption;
    }


    /**
     * Получаем информацию о выполненных этапах
     */
    public function partCompleted(ManufacturePartUid $ManufacturePartUid): void
    {
        $CompleteWorking = $this->activeWorkingManufacturePart
            ->fetchCompleteWorkingByManufacturePartAssociative($ManufacturePartUid);

        $caption = "<b>Производственная партия выполнена</b>";
        $caption .= "\n";
        $caption .= "\n";

        if($CompleteWorking)
        {
            $currentComplete = current($CompleteWorking);

            $caption .= 'Номер: <b>'.$currentComplete['part_number'].'</b>';
            $caption .= "\n";
            $caption .= 'Всего продукции: <b>'.$currentComplete['part_quantity'].' шт.</b>';
            $caption .= "\n";
            $caption .= "\n";


            /** Получаем продукцию в производственной партии и присваиваем к сообщению */
            $caption = $this->captionProducts($ManufacturePartUid, $caption);

            $caption .= '<b>Этапы производства:</b>';
            $caption .= "\n";
            foreach($CompleteWorking as $complete)
            {
                /* Пользователь выполнивший производственный этап */
                $caption .= $complete['working_name'].': <b>'.$complete['users_profile_username'].'</b>';
                $caption .= "\n";
            }
        }

        /** Отправляем сообщение о выполненной заявке */
        $this->telegramSendMessage
            ->delete($this->request->getId())
            ->message($caption)
            ->send();
    }

}