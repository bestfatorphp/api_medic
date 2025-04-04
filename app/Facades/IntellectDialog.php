<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array company()                                                                                           Получить организацию
 *
 * Методы, работа с отделами:
 * @method static array departments(array $options = [])                                                                    Получить список отделов организации
 * @method static array department(string $departmentId)                                                                    Получить отдел
 * @method static array createDepartment(string $name)                                                                      Добавить отдел
 * @method static array updateDepartment(string $departmentId, string $name)                                                Изменить отдел
 * @method static array deleteDepartment(string $departmentId)                                                              Удалить отдел
 *
 * Методы, работа с сотрудниками:
 * @method static array employees(array $options = [])                                                                      Получить список сотрудников
 * @method static array employee(string $employeeId)                                                                        Получить сотрудника
 * @method static array createEmployee(array $data)                                                                         Добавить сотрудника
 * @method static array updateEmployee(string $employeeId, array $data)                                                     Изменить сотрудника
 * @method static array employeeAuthorizationCode(string $employeeId)                                                       Получить код авторизации сотрудника
 *
 * Методы, работа с персонами:
 * @method static array persons(array $options = [])                                                                        Получить список персон
 * @method static array person(string $personId)                                                                            Получить персону
 * @method static array createPerson(array $data)                                                                           Добавить персону
 * @method static array updatePerson(string $personId, array $data)                                                         Изменить персону
 * @method static array deletePerson(string $personId)                                                                      Удалить персону
 *
 * Методы, работа с стоп-листом персон:
 * @method static array stopListOfPersons(array $options = [])                                                              Получить список персон в стоп листе
 * @method static array personInStopList(string $stopListId)                                                                Получить запись персоны в стоп листе
 * @method static array addPersonToStopList(string $person_id)                                                              Добавить персону в стоп лист
 * @method static array removePersonFromStopList(string $person_id)                                                         Исключить персону из стоп листа
 *
 * Методы, работа с тегами персоны:
 * @method static array assignTagToPerson(string $personId, string $tag_id)                                                 Назначение тега персоне
 * @method static array removeTagFromPerson(string $personId, string $tag_id)                                               Удалить тег у персоны
 *
 * Методы, работа с полями персоны:
 * @method static array setPersonFields(string $personId, array $data)                                                      Установить поля персоны
 * @method static array removePersonField(string $personId, string $field)                                                  Удалить поле персоны
 *
 * Методы, работа с чатами:
 * @method static array chats(array $options = [])                                                                          Получить список чатов
 * @method static array chat(string $chatId)                                                                                Получить чат
 * @method static array deleteChat(string $chatId)                                                                          Удалить чат
 *
 * Методы, работа с сообщениями:
 * @method static array messages(array $options = [])                                                                       Получить список сообщений
 * @method static array message(string $messageId)                                                                          Получить сообщение
 * @method static array sendMessage(array $data)                                                                            Отправить сообщение
 * @method static array deleteMessage(string $messageId)                                                                    Удалить сообщение
 * @method static array markMessageAsRead(string $messageId)                                                                Проставить сообщению признак "прочитано"
 * @method static array markMessageAsUnread(string $messageId)                                                              Снять у сообщения признак "прочитано"
 * @method static array getWhatsappTemplates(string $providerId, int|null $update)                                          Получить список шаблонов сообщений Whatsapp Business
 * @method static array assignToMessage(string $messageId, array $data)                                                     Назначить сотрудника или отдел сообщению
 *
 * Методы, работа с тегами:
 * @method static array tags(array $options = [])                                                                           Получить список тегов
 * @method static array tag(string $tagId)                                                                                  Получить тег
 * @method static array addTag(string $name)                                                                                Добавить тег
 *
 * Методы, работа с провайдерами организации:
 * @method static array providers(array $options = [])                                                                      Получить список провайдеров
 * @method static array provider(string $providerId)                                                                        Получить провайдера
 *
 * Методы, работа с файлом:
 * @method static array uploadFile(string $filePath)                                                                        Загрузить файл на сервер
 *
 * Методы, работа с Webhook:
 * @method static array webhook()                                                                                           Получить установленный Webhook
 * @method static array setWebhook(string $url, array $events)                                                              Добавить или изменить Webhook
 * @method static array deleteWebhook()                                                                                     Удалить Webhook
 */
class IntellectDialog extends Facade
{
    /**
     * Получение имени сервиса
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'intellect-dialog';
    }
}
