<?php

require_once(__DIR__.'/CIdeaTools.php');

class CIdeaConst {

    /**
     * Общие константы
     */
    const C_BLOG_URL  = 'idea';                                 // URL блога идей в модуле блогов (для проверки соответствия блога)
    const C_MAIL_FROM = 'Модуль УМШ <mailportal@sibur.ru>';     // Имя отправителя почты для модуля УМШ
    const C_MAIL_ENCODE = 'utf8';                               // Имя отправителя почты для модуля УМШ


    /**
     * Константы агента
     */
    const C_AGENT_MIN_RUN_INTERVAL  = 20;    // Интервал времени, в течении которого учитывать отчет как запущенный (после этого времени отчет считается высоконагруженным или зависшим)
    const C_AGENT_ATTEMPTS_NUMBER   = 3;     // Количество попыток выполнить одно и тоже задание перед отменой задания
    const C_AGENT_ATTEMPTS_INTERVAL = 60;    // Количество минут которое отводится на одну попытку выполнить задания
    const C_AGENT_FILE_SEND_SIZE_LIMIT = 7;  //(в МБ) Ограничнеие на величину файла отправляемого по почте (в base64 будет х1.35 )
    const C_AGENT_FILE_SAVE_TIME    = 84;    // Количество часов, которое файлы будут доступны для скачки с сервера
    const C_AGENT_LOAD_LEVEL_LOW    = 750;   // Количество записей в отчете, что бы нагрузка считалась низкой  (больше будет уже средняя)
    const C_AGENT_LOAD_LEVEL_MEDIUM = 2500;  // Количество записей в отчете, что бы нагрузка считалась средней (больше будет уже высокая)
    const C_AGENT_RUN_LIMIT_LOW     = 5;     // Количество отчетов с уровнем "низкий"  которые могут одновременной генерироваться
    const C_AGENT_RUN_LIMIT_MEDIUM  = 3;     // Количество отчетов с уровнем "средний" которые могут одновременной генерироваться
    const C_AGENT_RUN_LIMIT_HIGH    = 3;     // Количество отчетов с уровнем "высокий" которые могут одновременной генерироваться
    const C_AGENT_FILE_SAVE_PATH = '/bitrix/components/rarus/idea/tools/.files.for.export/';    // Путь от корня сайта до папки хранения файлов для скачки
    const C_AGENT_INIT_PATH      = '/bitrix/components/rarus/idea/tools/agent.php';             // Путь от корня сайта до скрипта с инициализацией Агента (для CURL)
    const C_AGENT_DOWNLOAD_PATH  = '/bitrix/components/rarus/idea/tools/download.php';          // Путь от корня сайта до скрипта скачки файлов


    /**
     * Константы идей
     */
    const C_IDEA_DATE_EDIT_TIME_LIMIT   = 14;  // Количество дней, через которое запрещено менять даты простым пользователям
    const C_IDEA_AUTHOR_EDIT_TIME_LIMIT = 7;   // Количество дней, через которое запрещено менять авторов простым пользователям
    const C_LEADERS_LAST_UPDATE_DAY     = 16;  // Число первого месяца нового квартала в котором будет произведено последнее автоматическое обновление прошлого квартала (лидеры УМШ)

    // Приоритет статусов (их последовательность)
    const C_STATUS_PRIORITY_NEW         = 1;
    const C_STATUS_PRIORITY_REWORK      = 2;
    const C_STATUS_PRIORITY_FAILED      = 3;
    const C_STATUS_PRIORITY_ACCEPT      = 4;
    const C_STATUS_PRIORITY_PROCESSING  = 5;
    const C_STATUS_PRIORITY_COMPLETED   = 6;
    const C_STATUS_PRIORITY_FINISH      = 7;

    // Коды путей реализации
    const C_WAYS_SELF       		= 'self';       // "Своими силами"
    const C_WAYS_CONTRACTOR    		= 'contractor'; // "С привлечением подрядчика"
    const C_WAYS_PROJECT       		= 'project';    // "Проектные решения"
    const C_WAYS_TMC      		    = 'tmc';        // "Закупка ТМЦ"

	// ID почтовых шаблонов для уведомлений
	const C_POST_NOTIFY_ID_NEW         = 546;
    const C_POST_NOTIFY_ID_REWORK      = 547;
    const C_POST_NOTIFY_ID_FAILED      = 548;
    const C_POST_NOTIFY_ID_ACCEPT      = 549;
    const C_POST_NOTIFY_ID_PROCESSING  = 550;
    const C_POST_NOTIFY_ID_COMPLETED   = 551;
    const C_POST_NOTIFY_ID_FINISH      = 552;
	


// ==============================
    /**
     * Возвращает массив с информацией о статусах,
     * какой статус на какой можно переключить.
     *
     * true\false - обозначает обязательность статусов в истории данного статуса (испольуется пока только для PREV, у NEXT - false)
     *
     * @return array
     */
    public static function getStatusesProperty()
    {
        static $arResult = null;
        if(!$arResult) {
            $arResult = array(
                CIdeaTools::getStatusIdByCode('DRAFT') => array(          // Черновик
                    'NEXT' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => false,
                    ),
                    'PREV' => array()
                ),
                CIdeaTools::getStatusIdByCode('NEW') => array(          // Новые
                    'NEXT' => array(
                        CIdeaTools::getStatusIdByCode('FAILED') => false,
                        CIdeaTools::getStatusIdByCode('REWORK') => false,
                        CIdeaTools::getStatusIdByCode('PROCESSING') => false,
                    ),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('DRAFT') => false,
                    )
                ),
                CIdeaTools::getStatusIdByCode('FAILED') => array(       // Отклонено
                    'NEXT' => array(),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => true,
                        CIdeaTools::getStatusIdByCode('REWORK') => false
                    )
                ),
                CIdeaTools::getStatusIdByCode('REWORK') => array(       // На доработке
                    'NEXT' => array(
                        CIdeaTools::getStatusIdByCode('FAILED') => false,
                        CIdeaTools::getStatusIdByCode('PROCESSING') => false,
                    ),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => true,
                        CIdeaTools::getStatusIdByCode('REWORK') => false,
                    )
                ),
                CIdeaTools::getStatusIdByCode('PROCESSING') => array(   // В работе
                    'NEXT' => array(
                        CIdeaTools::getStatusIdByCode('COMPLETED') => false,
                        CIdeaTools::getStatusIdByCode('FINISH') => false,
                    ),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => true,
                        CIdeaTools::getStatusIdByCode('REWORK') => false,
                    )
                ),
                CIdeaTools::getStatusIdByCode('COMPLETED') => array(     // Реализовано
                    'NEXT' => array(),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => true,
                        CIdeaTools::getStatusIdByCode('REWORK') => false,
                        CIdeaTools::getStatusIdByCode('PROCESSING') => true,
                    )
                ),
                CIdeaTools::getStatusIdByCode('FINISH') => array(       // Завершено
                    'NEXT' => array(),
                    'PREV' => array(
                        CIdeaTools::getStatusIdByCode('NEW') => true,
                        CIdeaTools::getStatusIdByCode('REWORK') => false,
                        CIdeaTools::getStatusIdByCode('PROCESSING') => true,
                    )
                )
            );
        }

        return $arResult;
    }  

    
    // Список полей которые при поиске требуют подзапросов
    // PS: можно учитывать поля по нагружености, менее нагружаемые сначала
    public static $arSubSearchFields = array(
        'UF_EMPLOYEE_CODE',
        'UF_EMPLOYEE_NAME',
        'CONTEXT',
    );

    // Массив с полями отображаемые по умолчанию
    public static $arViewFieldTableDefault = array(
        'ID' => array(
            'FIELD' => 'ID',
            'SORT'  => '1',
            'WIDTH' => '',
        ),
        'UF_INTERNAL_CODE' => array(
            'FIELD' => 'UF_INTERNAL_CODE',
            'SORT'  => '2',
            'WIDTH' => '',
        ),
        'SF_DEPARTMENT_LIST' => array(
            'FIELD' => 'SF_DEPARTMENT_LIST',
            'SORT'  => '3',
            'WIDTH' => '',
        ),
        'UF_EMPLOYEE_NAME' => array(
            'FIELD' => 'UF_EMPLOYEE_NAME',
            'SORT'  => '4',
            'WIDTH' => '',
        ),
        // Автор

        'DATE_PUBLISH' => array(
            'FIELD' => 'DATE_PUBLISH',
            'SORT'  => '5',
            'WIDTH' => '',
        ),
        'TITLE' => array(
            'FIELD' => 'TITLE',
            'SORT'  => '6',
            'WIDTH' => '',
        ),
        'DETAIL_TEXT' => array(
            'FIELD' => 'DETAIL_TEXT',
            'SORT'  => '7',
            'WIDTH' => '',
        ),
        'SF_STATUS' => array(
            'FIELD' => 'SF_STATUS',
            'SORT'  => '8',
            'WIDTH' => '',
        ),
    );

    // Список доступных типов правил для фильтрации
    public static $arAllowedRuleType = array(
        'ALL',
        'EQUAL', 'NOT_EQUAL',
        'CONTAINS', 'NOT_CONTAINS',
        'LESS', 'GREAT',
        'LESS_EQUAL', 'GREAT_EQUAL',
        'EMPTY', 'NOT_EMPTY',
    );

    // Список доступных для вывода полей с описанием их свойств
    //(ключ элемента = имя поля при выборки из БД, по которому можно искать)
    public static $arAllowedViewFields = array(
        // Номер идеи
        'ID'                    => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Номер',
            // Дублирующее свойства поля  (не обязательное, заполняется автоматически)
            'FIELD'     => 'ID',
            // Имя поля для отображения (не обязательное)
            // (false - поле не выводится и доступно только для поиска, должно быть FILTER->ENABLE = true)
            'DISPLAY'   => 'ID',
            // Поле доступно для сортировки (t/f)
            'SORTABLE'  => true,
            // Свойства фильтрации
            'FILTER'    => array(
                // Доступна фильтрация по полю (t/f)
                'ENABLE'    => true,
                // Поле для поиска и сортировки (не обязательное) (доделать возможность массива)
                'FIELD'     => 'ID',
                // Доступные для фильтрации типы правил (если не пуст, то используется он, а не дефолтный)
                'AVAILABLE' => array(),
                // Запрещенные для фильтрации типы (если задан, то используется array_diff(), но если только пуст AVAILABLE)
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                    'EMPTY', 'NOT_EMPTY',
                ),
                // Дополнительные правила, допустим если это особое поле или нужно сделать особую логику
                /*
                'RULES'     => array(
                    'ACTIVE' => array(
                        'WITH_VALUE' => false,
                    ),
                ),
                */
                // Отображение названий в фильтрах, если нужно поменять
                /*
                'DISPLAY'   => array(
                    'SHORT' => array(
                        'ALL'       => 'В',
                        'NOT_EMPTY' => 'АК',
                        'EMPTY'     => 'НО',
                    ),
                    'FULL' => array(
                        'ALL'       => 'Все',
                        'NOT_EMPTY' => 'Активные',
                        'EMPTY'     => 'Не отображать',
                    )
                ),
                */
            ),
        ),
        // Внутренний номер идеи
        'UF_INTERNAL_CODE'      => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Внутренний номер',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Решаемая проблема
        'TITLE'                 => array(
            'TYPE'      => 'TEXT',
            'NAME'      => 'Решаемая проблема',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'CONTAINS', 'NOT_CONTAINS',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Предлагаемая идея
        'DETAIL_TEXT'           => array(
            'TYPE'      => 'TEXT',
            'NAME'      => 'Предлагаемая идея',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'CONTAINS', 'NOT_CONTAINS',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Причина отказа
        'UF_FAIL_REASON'        => array(
            'TYPE'      => 'TEXT',
            'NAME'      => 'Причина отказа',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),
        // Статус выполнения
        'UF_STAT_EXEC'          => array(
            'TYPE'      => 'TEXT',
            'NAME'      => 'Статус выполнения',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),
        // Претендент ЛП
        'UF_COMP_BP_CONTENDER'  => array(
            'TYPE'      => 'CHECKBOX',
            'NAME'      => 'Претендент ЛП',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),

        // Дата создания идеи
        'DATE_CREATE'           => array(
            'TYPE'      => 'DATE',
            'NAME'      => 'Дата создания',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // Дата подачи идеи
        'DATE_PUBLISH'          => array(
            'TYPE'      => 'DATE',
            'NAME'      => 'Дата подачи',
            'DISPLAY'   => 'DATE_PUBLISH_DATE',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // Дата принятия идеи
        'UF_DATE_ACCEPT'        => array(
            'TYPE'      => 'DATE',
            'NAME'      => 'Дата принятия',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Дата передачи идеи
        'UF_TRANSFER_DATE'      => array(
            'TYPE'      => 'DATE',
            'NAME'      => 'Дата передачи',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Дата реализации идеи
        'UF_DATE_RELEASED'      => array(
            'TYPE'      => 'DATE',
            'NAME'      => 'Дата реализации',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Кол-во просмотров
        'VIEWS'                 => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Просмотров',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Кол-во комментариев
        'NUM_COMMENTS'          => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Комментариев',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Путь реализации
        'UF_RELEASED_WAY'       => array(
            'TYPE'      => 'DIALOG',
            'DISPLAY'   => 'SF_RELEASED_WAY',
            'NAME'      => 'Путь реализации',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Номер внутреннего заказа
        'UF_INNER_ORDER_NUM'    => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Номер внутреннего заказа',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),

        // Номер протокола заседания
        'UF_S_NUMBER'           => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Номер протокола заседания',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // Целевой срок реализации
        'UF_SOLUTION_DURATION'  => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Целевой срок реализации',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Фактический срок реализации
        'UF_TIME_NEED'          => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Фактический срок реализации',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Суммарная оценка
        'UF_TOTAL_RATING'       => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Суммарная оценка',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Сумма вознаграждения
        'UF_IDEA_PRICE'         => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Сумма вознаграждения',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Суммарный эффект Soft
        'UF_TOTAL_EFF_SOFT'     => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Эффект Soft',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),
        // Суммарный эффект Hard
        'UF_TOTAL_EFF_HARD'     => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Эффект Hard',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                ),
            ),
        ),

        // Автор(ы) идеи
        'UF_EMPLOYEE_NAME'    => array(
            'TYPE'      => 'LIST',
            'NAME'      => 'Автор(ы) идеи',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'CONTAINS', 'NOT_CONTAINS',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Табель(я)
        'UF_EMPLOYEE_CODE'      => array(
            'TYPE'      => 'LIST',
            'NAME'      => 'Табель(я)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // Автор(ы) с табелем
        'SF_EMPLOYEES'          => array(
            'TYPE'      => 'LIST',
            'NAME'      => 'Автор(ы) с табелем',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'FIELD'     => array(
                    'UF_EMPLOYEE_CODE', 'UF_EMPLOYEE_NAME',
                ),
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // График работы
        'UF_AUTHOR_SHIFT'       => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'График работы',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'CONTAINS', 'NOT_CONTAINS',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Контактный телефон
        'UF_AUTHOR_PHONE'       => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Контактный телефон',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),
        // Участие автора
        'UF_AUTHOR_PART_CHECK'  => array(
            'TYPE'      => 'CHECKBOX',
            'NAME'      => 'Участие автора',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Ответственный за доработку
        'UF_USER_DEV'           => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Ответственный за доработку',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),
        // Ответственный за внедрение
        'UF_USER_APP'           => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Ответственный за внедрение',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),

        // ID Пользователя
        'AUTHOR_ID'             => array(
            'TYPE'      => 'INT',
            'NAME'      => 'ID Пользователя',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'CONTAINS', 'NOT_CONTAINS',
                    'EMPTY', 'NOT_EMPTY',
                ),
            ),
        ),
        // Почта Пользователя
        'AUTHOR_EMAIL'          => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Email Пользователя',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(
                    'LESS', 'GREAT',
                    'LESS_EQUAL', 'GREAT_EQUAL',
                ),
            ),
        ),

        /* Поля АЛИАСЫ, поиск возможен, но по полю (FILTER->FIELD) */
        // Теги
        'CATEGORY'              => array(
            'TYPE'      => 'LIST',
            'NAME'      => 'Теги',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => false,
                'FIELD'     => 'CATEGORY_ID',
                'AVAILABLE' => array(
                    'ALL',
                    'EMPTY', 'NOT_EMPTY', // Проверить, почему не пашет на "пусто" "не пусто"
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность
        'SF_DEPARTMENT_LIST'    => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Принадлежность',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_AUTHOR_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (1)
        'SF_COMPANY'            => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Принадлежность (1)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_AUTHOR_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (2)
        'SF_DEPARTMENT'         => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Принадлежность (2)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_AUTHOR_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (3)
        'SF_DIVISION'           => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Принадлежность (3)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_AUTHOR_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Передано
        'SF_TRANSFER_LIST'      => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Передано',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_TRANSFER_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (1)
        'SF_TRANSFER_COMPANY'   => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Передано (1)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_TRANSFER_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (2)
        'SF_TRANSFER_DEPARTMENT' => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Передано (2)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_TRANSFER_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Принадлежность (3)
        'SF_TRANSFER_DIVISION'  => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Передано (3)',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_TRANSFER_DEP',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                    'EMPTY', 'NOT_EMPTY',
                ),
                'FORBIDDEN' => array(),
            ),
        ),
        // Статус
        'SF_STATUS'             => array(
            'TYPE'      => 'DIALOG',
            'NAME'      => 'Статус',
            //'DISPLAY'   => 'SF_STATUS',
            'SORTABLE'  => true,
            'FILTER'    => array(
                'ENABLE'    => true,
                'FIELD'     => 'UF_STATUS',
                'AVAILABLE' => array(
                    'ALL',
                    'EQUAL', 'NOT_EQUAL',
                ),
                'FORBIDDEN' => array(),
            ),
        ),

        /* Искускуственные поля, фильтрация по ним возможна ( они как опции или доп логика ) */
        // Отображать полученные
        'SF_SHOW_TRANSFER'  => array(
            'TYPE'      => 'CHECKBOX',
            'NAME'      => 'Отображать полученные',
            'DISPLAY'   => false,
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => true,
                'AVAILABLE' => array(
                    'ALL',
                    'NOT_EMPTY',
                    'ACTIVE',
                    'EMPTY',
                ),
                'RULES'     => array(
                    'ACTIVE' => array(
                        'WITH_VALUE' => false,
                    ),
                ),
                'FORBIDDEN' => array(),
                'DISPLAY'   => array(
                    'SHORT' => array(
                        'NOT_EMPTY' => 'Д',
                        'ACTIVE'    => 'А',
                        'EMPTY'     => 'Н',
                    ),
                    'FULL' => array(
                        'NOT_EMPTY' => 'Да',
                        'ACTIVE'    => 'Активные',
                        'EMPTY'     => 'Нет',
                    )
                ),
            ),
        ),


        /* Искускуственные поля, фильтрация по ним НЕвозможна ( рукотворные :Р ) */
        'SF_COMMENTS'           => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Комментарии [текст]',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Текущий срок реализации
        'REALISE_DURATION'      => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Текущий срок реализации',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Срок доработки
        'REWORK_DURATION'       => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Срок доработки',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Уровень просрочки в %
        'EXPIRED_PERCENT'       => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Уровень просрочки в %',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Светофор
        'TRAFFIC_LIGHT_STATUS'  => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Светофор',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Имя Пользователя
        'AuthorName'            => array(
            'TYPE'      => 'INT',
            'NAME'      => 'Имя пользователя',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Оценка совета
        // Снижение потерь и сокращение затрат
        'SF_CAT_LOSSES'         => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Снижение потерь и сокращение затрат',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Увеличение выпуска продукции
        'SF_CAT_OUTPUT'         => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Увеличение выпуска продукции',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Повышение качества продукции
        'SF_CAT_QUALITY'        => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Повышение качества продукции',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Повышение энергоэффективности
        'SF_CAT_EFFICIENCY'     => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Повышение энергоэффективности',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Снижение трудоемкости при выполнении операции
        'SF_CAT_LABORIOUSNESS'  => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Снижение трудоемкости при выполнении операции',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Охрана труда и промышленная безопасность
        'SF_CAT_PROTECTION'     => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Охрана труда и промышленная безопасность',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Улучшение порядка на рабочих местах
        'SF_CAT_ORDERLINESS'    => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Улучшение порядка на рабочих местах',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Улучшение порядка на рабочих местах
        'SF_CAT_CUSTOMER_FOCUS' => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Развитие клиентоориентированности',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Улучшение порядка на рабочих местах
        'SF_CAT_DEMAND_SALES'   => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Развитие спроса и объема продаж',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),
        // Улучшение порядка на рабочих местах
        'SF_CAT_SALES_EFFECTIVENESS'    => array(
            'TYPE'      => 'STRING',
            'NAME'      => 'Развитие эффективности продаж',
            'SORTABLE'  => false,
            'FILTER'    => array(
                'ENABLE'    => false,
                'AVAILABLE' => array(),
                'FORBIDDEN' => array(),
            ),
        ),



        /*
         *
         *
         *
         *
         * // Поля которые не выведены, но возможны для поиска
         * Создатель Логин (AUTHOR_LOGIN)
         * Создатель Имя (AUTHOR_NAME)
         * Создатель Фамилия (AUTHOR_LAST_NAME)
         *
         */
    );



} // class