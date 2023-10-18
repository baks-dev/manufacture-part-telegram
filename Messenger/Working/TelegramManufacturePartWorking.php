<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\Working;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Auth\Telegram\Repository\UserProfileByChat\UserProfileByChatInterface;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\AllWorkingByManufacturePart\AllWorkingByManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\ProductsByManufacturePart\ProductsByManufacturePartInterface;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartDone;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartWorking;
use BaksDev\Manufacture\Part\Type\Id\ManufacturePartUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\Callback\TelegramCallbackMessage;
use BaksDev\Telegram\Bot\Repository\UsersTableTelegramSettings\GetTelegramBotSettingsInterface;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class TelegramManufacturePartWorking
{
    private iterable $reference;

    private TelegramSendMessage $telegramSendMessage;
    private GetTelegramBotSettingsInterface $settings;
    private EntityManagerInterface $entityManager;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;
    private AllWorkingByManufacturePartInterface $allWorkingByManufacturePart;
    private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart;
    private ProductsByManufacturePartInterface $productsByManufacturePart;
    private TranslatorInterface $translator;
    private UserProfileByChatInterface $profileByChat;
    private AppCacheInterface $cache;

    public function __construct(
        #[TaggedIterator('baks.reference.choice')] iterable $reference,
        EntityManagerInterface $entityManager,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        TelegramSendMessage $telegramSendMessage,
        GetTelegramBotSettingsInterface $settings,
        AllWorkingByManufacturePartInterface $allWorkingByManufacturePart,
        ProductsByManufacturePartInterface $productsByManufacturePart,
        TranslatorInterface $translator,
        UserProfileByChatInterface $profileByChat,
        AppCacheInterface $cache
    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->settings = $settings;
        $this->entityManager = $entityManager;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
        $this->activeWorkingManufacturePart = $activeWorkingManufacturePart;
        $this->allWorkingByManufacturePart = $allWorkingByManufacturePart;


        $this->productsByManufacturePart = $productsByManufacturePart;
        $this->reference = $reference;
        $this->translator = $translator;
        $this->profileByChat = $profileByChat;
        $this->cache = $cache;
    }

    /**
     * Получаем состояние партии и принимаем количество указанного товара
     */
    public function __invoke(TelegramCallbackMessage $message): void
    {
        if(!$message->getClass() instanceof ManufacturePartWorking)
        {
            return;
        }

        $AppCache = $this->cache->init('telegram-bot');


        /** Получаем активный профиль пользователя чата */
        $UserProfileUid = $this->activeProfileByAccountTelegram
            ->getActiveProfileUidOrNullResultByChat($message->getChat());

        if($UserProfileUid === null)
        {
            /** Сбрасываем идентификатор и callback */
            $AppCache->delete('identifier-'.$message->getChat());
            $AppCache->delete('callback-'.$message->getChat());

            return;
        }

        /**
         * Присваиваем настройки Telegram
         */

        $settings = $this->settings->settings();

        $this->telegramSendMessage
            ->token($settings->getToken())
            ->chanel($message->getChat());


        /**
         * Получаем заявку на производство
         */
        $ManufacturePart = $this->entityManager
            ->getRepository(ManufacturePart::class)
            ->find($message->getClass());


        if(!$ManufacturePart)
        {
            /** Отправляем сообщение о выполненной заявке  */
            $caption = '<b>Производство:</b>';
            $caption .= "\n";
            $caption .= 'Вышлите QR продукта, либо его идентификатор';

            $response = $this->telegramSendMessage
                ->message($caption)
                ->send(false);

            /** Сохраняем последнее сообщение */
            $lastMessage = $AppCache->getItem('last-'.$message->getChat());
            $lastMessage->set($response['result']['message_id']);
            $lastMessage->expiresAfter(DateInterval::createFromDateString('1 day'));
            $AppCache->save($lastMessage);


            /** Сбрасываем фиксацию производственной партии и идентификатор */
            $AppCache->delete('identifier-'.$message->getChat());
            $AppCache->delete('fixed-'.$ManufacturePart->getId());

            return;
        }


        /** Получаем активное рабочее состояние производственной партии */
        $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
            ->findNextWorkingByManufacturePart($ManufacturePart->getId());


        if(!$UsersTableActionsWorkingUid)
        {
            /** Сбрасываем идентификатор и фиксацию */
            $AppCache->delete('identifier-'.$message->getChat());
            $AppCache->delete('fixed-'.$ManufacturePart->getId());

            /** Получаем информацию о выполненных этапах */
            $CompleteWorking = $this->activeWorkingManufacturePart
                ->fetchCompleteWorkingByManufacturePartAssociative($ManufacturePart->getId());

            $caption = "Заявка выполнена";
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
                $caption = $this->captionProducts($ManufacturePart->getId(), $caption);

                $caption .= '<b>Этапы производства:</b>';
                $caption .= "\n";
                foreach($CompleteWorking as $complete)
                {
                    $caption .= $complete['working_name'].': <b>'.$complete['users_profile_username'].'</b>';
                    $caption .= "\n";
                }
            }


            /** Отправляем сообщение о выполненной заявке  */
            $this->telegramSendMessage
                ->message($caption)
                ->send();

            return;
        }


        /**
         * Проверяем, что партия не фиксированна за другим сотрудником
         */
        $itemManufacturePart = $AppCache->getItem('fixed-'.$ManufacturePart->getId());
        $fixedManufacturePart = $itemManufacturePart->get();

        if($fixedManufacturePart !== null && $fixedManufacturePart !== $message->getChat())
        {

            /** Получаем профиль пользователя зафиксировавшего партию */
            $profileName = $this->profileByChat->getUserProfileNameByChat($fixedManufacturePart);

            /** Отправляем сообщение фиксации производственной партии  */
            $caption = '<b>Производство:</b>';
            $caption .= "\n";
            $caption .= sprintf('Производственная партия %s выполняется пользователем %s!',
                $ManufacturePart->getNumber(), $profileName
            );

            $response = $this->telegramSendMessage
                ->message($caption)
                ->send(false);

            /** Сохраняем последнее сообщение */
            $lastMessage = $AppCache->getItem('last-'.$message->getChat());
            $lastMessage->set($response['result']['message_id']);
            $lastMessage->expiresAfter(DateInterval::createFromDateString('1 day'));
            $AppCache->save($lastMessage);

            /** Сбрасываем идентификатор */
            $AppCache->delete('identifier-'.$message->getChat());

            return;
        }


        /** Получаем этапы производства указанной производственной партии  */
        $ManufacturePartWorking = $this->allWorkingByManufacturePart
            ->fetchAllWorkingByManufacturePartAssociative($ManufacturePart->getId());


        $caption = '<b>Производственная партия:</b>';
        $caption .= "\n";
        $caption .= "\n";


        $caption .= 'Номер: <b>'.$ManufacturePart->getNumber().'</b>';
        $caption .= "\n";
        $caption .= 'Всего продукции: <b>'.$ManufacturePart->getQuantity().' шт.</b>';
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
                $caption .= ' <b>'.$ManufacturePart->getQuantity().' шт </b>';
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

      
        $menu[] = [
            'text' => sprintf('Выполнено "%s" все %s шт.',
                $currentWorkingName,
                $ManufacturePart->getQuantity()
            ),

            'callback_data' => ManufacturePartDone::class
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 1),
        ]);

        $this->telegramSendMessage
            ->message($caption)
            ->markup($markup)
            ->send();

        /**
         * Фиксируем производственную партию за пользователем
         */
        $fixedManufacturePart = $AppCache->getItem('fixed-'.$ManufacturePart->getId());
        $fixedManufacturePart->set($message->getChat());
        $fixedManufacturePart->expiresAfter(DateInterval::createFromDateString('1 day'));
        $AppCache->save($fixedManufacturePart);


    }


    public function captionProducts(ManufacturePartUid $part, string $caption): string
    {
        $caption .= '<b>Продукция:</b>';
        $caption .= "\n";
        $products = $this->productsByManufacturePart->getAllProductsByManufacturePart($part);

        foreach($products as $key => $product)
        {
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
                        $caption .= $this->translator->trans($product['product_variation_value'], domain: $reference->domain()).' ';
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

}