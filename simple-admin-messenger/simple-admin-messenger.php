<?php
/*
 * Plugin Name: Simple Admin Messenger (No DB, GUID)
 * Description: Отправка сообщений администраторам сайтов в личный кабинет в Wordpress Multisite
 * Version: 1.11c
 * Author: Andrew Arutunyan & Grok
 * Network: true
 */

if ( ! is_multisite() ) {
    return;
}

// Пути к файлам
define( 'SAM_MESSAGES_FILE', WP_CONTENT_DIR . '/sent_messages.txt' );
define( 'SAM_READ_STATUS_FILE', WP_CONTENT_DIR . '/sam_read_status.txt' );

// Регистрируем меню для супер админа
add_action( 'network_admin_menu', 'sam_register_menu' );
function sam_register_menu() {
    add_menu_page(
        'Отправить сообщение',
        'Сообщения',
        'manage_network',
        'simple-admin-messenger',
        'sam_admin_page',
        'dashicons-megaphone',
        3
    );
    add_submenu_page(
        'simple-admin-messenger',
        'Отправить сообщение',
        'Отправить',
        'manage_network',
        'simple-admin-messenger',
        'sam_admin_page'
    );
    add_submenu_page(
        'simple-admin-messenger',
        'Отправленные сообщения',
        'Отправленные',
        'manage_network',
        'sam-sent-messages',
        'sam_sent_messages_page'
    );
    add_submenu_page(
        'simple-admin-messenger',
        'Редактировать сообщения',
        'Редактировать',
        'manage_network',
        'sam-edit-messages',
        'sam_edit_messages_page'
    );
    add_submenu_page(
        'simple-admin-messenger',
        'Статусы прочтения',
        'Статусы',
        'manage_network',
        'sam-read-status',
        'sam_read_status_page'
    );
}

// Добавляем меню для админов подсайтов
add_action( 'admin_menu', 'sam_register_user_menu' );
function sam_register_user_menu() {
    if ( current_user_can( 'manage_options' ) && ! is_network_admin() ) {
        $unread_count = sam_get_unread_count();
        $menu_title = 'Сообщения';
        if ( $unread_count > 0 ) {
            $menu_title .= ' <span class="awaiting-mod">' . $unread_count . '</span>';
        }
        add_menu_page(
            'Сообщения от сети',
            $menu_title,
            'manage_options',
            'sam-messages',
            'sam_user_messages_page',
            'dashicons-email',
            3
        );
    }
}

// Обработка "Отметить прочитанным" на хуке admin_init
add_action( 'admin_init', 'sam_handle_mark_read' );
function sam_handle_mark_read() {
    if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
        return;
    }

    if ( isset( $_GET['page'] ) && $_GET['page'] === 'sam-messages' && isset( $_GET['mark_read'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( ! check_admin_referer( 'sam_mark_read' ) ) {
            add_settings_error( 'sam_messages', 'nonce_error', 'Ошибка проверки безопасности. Попробуйте снова.', 'error' );
        } else {
            $user_id = get_current_user_id();
            $guid = sanitize_text_field( $_GET['mark_read'] );
            $read_status = sam_get_read_status();
            
            if ( ! isset( $read_status[$user_id] ) ) {
                $read_status[$user_id] = array();
            }
            $read_status[$user_id][$guid] = true;
            file_put_contents( SAM_READ_STATUS_FILE, json_encode( $read_status, JSON_UNESCAPED_UNICODE ), LOCK_EX );
            add_settings_error( 'sam_messages', 'marked_read', 'Сообщение отмечено как прочитанное.', 'success' );
        }
        $redirect_url = remove_query_arg( array( 'mark_read', '_wpnonce' ), admin_url( 'admin.php?page=sam-messages' ) );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}

// Обработка удаления сообщения на хуке admin_init
add_action( 'admin_init', 'sam_handle_delete_message' );
function sam_handle_delete_message() {
    if ( ! current_user_can( 'manage_network' ) || ! isset( $_GET['page'] ) || $_GET['page'] !== 'sam-sent-messages' ) {
        return;
    }

    if ( isset( $_GET['delete'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( ! check_admin_referer( 'sam_delete_message' ) ) {
            add_settings_error( 'sam_messages', 'nonce_error', 'Ошибка проверки безопасности. Попробуйте снова.', 'error' );
        } else {
            $guid = sanitize_text_field( $_GET['delete'] );
            $messages = sam_get_sent_messages();

            if ( isset( $messages[$guid] ) ) {
                unset( $messages[$guid] );
                $new_content = '';
                foreach ( $messages as $msg ) {
                    $new_content .= json_encode( $msg, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
                }
                file_put_contents( SAM_MESSAGES_FILE, $new_content, LOCK_EX );
                add_settings_error( 'sam_messages', 'message_deleted', 'Сообщение успешно удалено.', 'success' );
            } else {
                add_settings_error( 'sam_messages', 'delete_not_found', 'Сообщение с GUID ' . $guid . ' не найдено.', 'error' );
            }
        }
        $redirect_url = remove_query_arg( array( 'delete', '_wpnonce' ), network_admin_url( 'admin.php?page=sam-sent-messages' ) );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}

// Обработка редактирования и удаления файлов на хуке admin_init
add_action( 'admin_init', 'sam_handle_edit_messages' );
function sam_handle_edit_messages() {
    if ( ! current_user_can( 'manage_network' ) || ! isset( $_GET['page'] ) || $_GET['page'] !== 'sam-edit-messages' ) {
        return;
    }

    if ( isset( $_POST['sam_edit_submit'] ) && check_admin_referer( 'sam_edit_messages' ) ) {
        $new_content = sanitize_textarea_field( $_POST['sam_messages_content'] );
        file_put_contents( SAM_MESSAGES_FILE, $new_content, LOCK_EX );
        add_settings_error( 'sam_messages', 'messages_updated', 'Сообщения успешно обновлены.', 'success' );
        wp_safe_redirect( network_admin_url( 'admin.php?page=sam-edit-messages' ) );
        exit;
    }

    if ( isset( $_GET['delete_file'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( ! check_admin_referer( 'sam_delete_file_' . $_GET['delete_file'] ) ) {
            add_settings_error( 'sam_messages', 'nonce_error', 'Ошибка проверки безопасности. Попробуйте снова.', 'error' );
        } else {
            $file_to_delete = $_GET['delete_file'] === 'messages' ? SAM_MESSAGES_FILE : SAM_READ_STATUS_FILE;
            if ( file_exists( $file_to_delete ) && unlink( $file_to_delete ) ) {
                add_settings_error( 'sam_messages', 'file_deleted', 'Файл успешно удален.', 'success' );
            } else {
                add_settings_error( 'sam_messages', 'delete_error', 'Ошибка при удалении файла.', 'error' );
            }
        }
        wp_safe_redirect( network_admin_url( 'admin.php?page=sam-edit-messages' ) );
        exit;
    }
}

// Основная страница отправки
function sam_admin_page() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( 'У вас нет доступа' );
    }

    if ( isset( $_POST['sam_submit'] ) && check_admin_referer( 'sam_send_message' ) ) {
        $subject = sanitize_text_field( $_POST['sam_subject'] );
        $message = wp_kses_post( $_POST['sam_message'] );
        sam_send_message( $subject, $message );
        echo '<div class="updated"><p>Сообщения отправлены!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Отправка сообщений</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'sam_send_message' ); ?>
            <div class="sam-form-field">
                <label for="sam_subject">Тема</label>
                <input type="text" id="sam_subject" name="sam_subject" class="sam-full-width" required>
            </div>
            <div class="sam-form-field">
                <label for="sam_message">Сообщение</label>
                <?php
                wp_editor(
                    '',
                    'sam_message',
                    array(
                        'textarea_rows' => 10,
                        'media_buttons' => true,
                        'teeny'         => false,
                        'quicktags'     => true,
                    )
                );
                ?>
            </div>
            <?php submit_button( 'Отправить', 'primary', 'sam_submit' ); ?>
        </form>
    </div>
    <?php
}

// Страница отправленных сообщений для супер админа
function sam_sent_messages_page() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( 'У вас нет доступа' );
    }

    $sent_messages = sam_get_sent_messages();
    settings_errors( 'sam_messages' );
    ?>
    <div class="wrap">
        <h1>Отправленные сообщения</h1>
        <?php if ( empty( $sent_messages ) ) : ?>
            <p>Отправленных сообщений пока нет.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped sam-sent-messages-table">
                <thead>
                    <tr>
                        <th class="sam-subject">Тема</th>
                        <th class="sam-date">Дата</th>
                        <th class="sam-guid">GUID</th>
                        <th class="sam-action">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sent_messages as $guid => $msg ) : ?>
                        <tr>
                            <td class="sam-subject"><strong><?php echo esc_html( $msg['subject'] ); ?></strong></td>
                            <td class="sam-date"><?php echo esc_html( $msg['date'] ); ?></td>
                            <td class="sam-guid"><?php echo esc_html( $guid ); ?></td>
                            <td class="sam-action">
                                <a href="<?php echo wp_nonce_url( add_query_arg( 'delete', $guid ), 'sam_delete_message' ); ?>" class="button">Удалить</a>
                            </td>
                        </tr>
			<tr class="sam-message-row">
			    <td colspan="4">
			    <div class="sam-message-content" style="max-width: 100%; overflow-x: auto;">
				<?php echo wp_kses_post($msg['message']); ?>
			    </div>
			    <hr class="sam-message-divider">
			</td>
			</tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// Страница редактирования сообщений для супер админа
function sam_edit_messages_page() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( 'У вас нет доступа' );
    }

    $file_content = file_exists( SAM_MESSAGES_FILE ) ? file_get_contents( SAM_MESSAGES_FILE ) : '';
    settings_errors( 'sam_messages' );
    ?>
    <div class="wrap">
        <h1>Редактировать сообщения</h1>
        <p>Здесь вы можете вручную отредактировать содержимое файла <code><?php echo esc_html( SAM_MESSAGES_FILE ); ?></code>. Каждое сообщение должно быть в формате JSON и на новой строке.</p>
        <form method="post" action="">
            <?php wp_nonce_field( 'sam_edit_messages' ); ?>
            <textarea name="sam_messages_content" rows="20" class="large-text code"><?php echo esc_textarea( $file_content ); ?></textarea>
            <?php submit_button( 'Сохранить изменения', 'primary', 'sam_edit_submit' ); ?>
        </form>
        <div class="sam-delete-buttons" style="margin-top: 20px;">
            <a href="<?php echo wp_nonce_url( add_query_arg( 'delete_file', 'messages' ), 'sam_delete_file_messages' ); ?>" class="button button-secondary" onclick="return confirm('Вы уверены, что хотите удалить файл сообщений?');">Удалить файл сообщений</a>
            <a href="<?php echo wp_nonce_url( add_query_arg( 'delete_file', 'status' ), 'sam_delete_file_status' ); ?>" class="button button-secondary" onclick="return confirm('Вы уверены, что хотите удалить файл статусов?');">Удалить файл статусов</a>
        </div>
    </div>
    <?php
}

// Новая страница статусов прочтения
function sam_read_status_page() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( 'У вас нет доступа' );
    }

    $read_status = sam_get_read_status();
    $sent_messages = sam_get_sent_messages();
    settings_errors( 'sam_messages' );
    ?>
    <div class="wrap">
        <h1>Статусы прочтения</h1>
        <?php if ( empty( $read_status ) ) : ?>
            <p>Нет данных о прочтении сообщений.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped sam-read-status-table">
                <thead>
                    <tr>
                        <th class="sam-user">Пользователь</th>
                        <th class="sam-message">Сообщение (GUID)</th>
                        <th class="sam-subject">Тема</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $read_status as $user_id => $messages ) : ?>
                        <?php
                        $user_info = get_userdata( $user_id );
                        $user_name = $user_info ? esc_html( $user_info->display_name ) : 'Неизвестный пользователь (ID: ' . $user_id . ')';
                        foreach ( $messages as $guid => $status ) :
                            if ( $status ) : // Показываем только прочитанные сообщения
                                $subject = isset( $sent_messages[$guid] ) ? esc_html( $sent_messages[$guid]['subject'] ) : 'Сообщение удалено';
                        ?>
                            <tr>
                                <td class="sam-user"><?php echo $user_name; ?></td>
                                <td class="sam-message"><?php echo esc_html( $guid ); ?></td>
                                <td class="sam-subject"><?php echo $subject; ?></td>
                            </tr>
                        <?php endif; endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// Страница сообщений для админов
function sam_user_messages_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'У вас нет доступа' );
    }

    $user_id = get_current_user_id();
    $messages = sam_get_sent_messages();
    $read_status = sam_get_read_status();
    $user_read = isset( $read_status[$user_id] ) ? $read_status[$user_id] : array();

    settings_errors( 'sam_messages' );
    ?>
    <div class="wrap">
        <h1>Сообщения от администратора сети</h1>
        <?php if ( empty( $messages ) ) : ?>
            <p>Сообщений пока нет.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped sam-messages-table">
                <thead>
                    <tr>
                        <th class="sam-subject">Тема</th>
                        <th class="sam-date">Дата</th>
                        <th class="sam-status">Статус</th>
                        <th class="sam-action">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $messages as $guid => $msg ) : ?>
                        <tr>
                            <td class="sam-subject"><strong><?php echo esc_html( $msg['subject'] ); ?></strong></td>
                            <td class="sam-date"><?php echo esc_html( $msg['date'] ); ?></td>
                            <td class="sam-status"><?php echo isset( $user_read[$guid] ) ? 'Прочитано' : 'Новое'; ?></td>
                            <td class="sam-action">
                                <?php if ( ! isset( $user_read[$guid] ) ) : ?>
                                    <a href="<?php echo wp_nonce_url( add_query_arg( 'mark_read', $guid ), 'sam_mark_read' ); ?>" class="button">Отметить прочитанным</a>
                                <?php endif; ?>
                            </td>
                        </tr>
			<tr class="sam-message-row">
			    <td colspan="4">
			        <div class="sam-message-content" style="max-width: 100%; overflow-x: auto;">
			            <?php echo wp_kses_post($msg['message']); ?>
			        </div>
			        <hr class="sam-message-divider">
			    </td>
			</tr>                   
		 <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// Функция отправки сообщений
function sam_send_message( $subject, $message ) {
    $guid = 'msg_' . microtime(true) . '_' . mt_rand(10000, 99999);
    $sent_message = array(
        'guid'    => $guid,
        'subject' => $subject,
        'message' => $message,
        'date'    => current_time( 'mysql' ),
    );
    $json_message = json_encode( $sent_message, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
    file_put_contents( SAM_MESSAGES_FILE, $json_message, FILE_APPEND | LOCK_EX );
}

// Чтение отправленных сообщений из файла
function sam_get_sent_messages() {
    if ( ! file_exists( SAM_MESSAGES_FILE ) ) {
        return array();
    }

    $lines = file( SAM_MESSAGES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    $messages = array();

    foreach ( $lines as $line ) {
        $msg = json_decode( $line, true );
        if ( $msg && isset( $msg['guid'], $msg['subject'], $msg['message'], $msg['date'] ) ) {
            $messages[$msg['guid']] = $msg;
        }
    }

    return $messages;
}

// Чтение статусов прочтения
function sam_get_read_status() {
    if ( ! file_exists( SAM_READ_STATUS_FILE ) ) {
        return array();
    }
    $content = file_get_contents( SAM_READ_STATUS_FILE );
    $status = json_decode( $content, true );
    return is_array( $status ) ? $status : array();
}

// Подсчет непрочитанных сообщений
function sam_get_unread_count() {
    $user_id = get_current_user_id();
    $messages = sam_get_sent_messages();
    $read_status = sam_get_read_status();
    $user_read = isset( $read_status[$user_id] ) ? $read_status[$user_id] : array();

    $unread_count = 0;
    foreach ( $messages as $guid => $msg ) {
        if ( ! isset( $user_read[$guid] ) ) {
            $unread_count++;
        }
    }
    return $unread_count;
}

// Стили
add_action( 'admin_head', 'sam_admin_styles' );
function sam_admin_styles() {
    ?>
    <style>
        .form-table th {
            width: 150px;
        }
        .wp-editor-container {
            max-width: 100% !important;
            width: 100% !important;
        }
        #sam_message_ifr {
            width: 100% !important;
            max-width: 100% !important;
        }
        #sam_message_ifr p {
            margin: 0 !important;
            padding: 0 !important;
        }
        #sam_message_ifr body {
            margin: 0 !important;
            padding: 5px !important;
        }
        .wp-list-table.widefat {
            width: 100%;
        }
        /* Стили для формы отправки */
        .sam-form-field {
            margin-bottom: 20px;
        }
        .sam-form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .sam-form-field input.sam-full-width {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        /* Стили для таблицы отправленных сообщений (супер админ) */
        .sam-sent-messages-table th.sam-subject,
        .sam-sent-messages-table td.sam-subject {
            width: 40%;
        }
        .sam-sent-messages-table th.sam-date,
        .sam-sent-messages-table td.sam-date {
            width: 20%;
        }
        .sam-sent-messages-table th.sam-guid,
        .sam-sent-messages-table td.sam-guid {
            width: 25%;
        }
        .sam-sent-messages-table th.sam-action,
        .sam-sent-messages-table td.sam-action {
            width: 15%;
        }
        .sam-sent-messages-table td.sam-subject strong {
            font-weight: 700;
        }
        .sam-sent-messages-table .sam-message-row td {
            padding-bottom: 10px;
        }
        .sam-sent-messages-table .sam-message-divider {
            border: 0;
            border-top: 1px dashed #ccc;
            margin-top: 10px;
        }
        /* Стили для таблицы сообщений администраторов */
        .sam-messages-table th.sam-subject,
        .sam-messages-table td.sam-subject {
            width: 50%;
        }
        .sam-messages-table th.sam-date,
        .sam-messages-table td.sam-date {
            width: 20%;
        }
        .sam-messages-table th.sam-status,
        .sam-messages-table td.sam-status {
            width: 15%;
        }
        .sam-messages-table th.sam-action,
        .sam-messages-table td.sam-action {
            width: 15%;
        }
        .sam-messages-table td.sam-subject strong {
            font-weight: 700;
        }
        .sam-messages-table .sam-message-row td {
            padding-bottom: 10px;
        }
        .sam-messages-table .sam-message-divider {
            border: 0;
            border-top: 1px dashed #ccc;
            margin-top: 10px;
        }
        /* Стили для таблицы статусов прочтения */
        .sam-read-status-table th.sam-user,
        .sam-read-status-table td.sam-user {
            width: 30%;
        }
        .sam-read-status-table th.sam-message,
        .sam-read-status-table td.sam-message {
            width: 40%;
        }
        .sam-read-status-table th.sam-subject,
        .sam-read-status-table td.sam-subject {
            width: 30%;
        }
        /* Стили для редактирования сообщений */
        .large-text.code {
            width: 100%;
            font-family: monospace;
            font-size: 14px;
        }
        .sam-delete-buttons .button {
            margin-right: 10px;
        }
        .sam-message-content {
            max-width: 100%;
            overflow-x: auto;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
    <?php
}