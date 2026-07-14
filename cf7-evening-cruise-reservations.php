<?php

/**
 * Plugin Name: Rezerwacje rejsu wieczornego dla CF7
 * Description: Rozszerza Contact Form 7 o globalną rezerwację miejsc na jeden rejs wieczorny dziennie.
 * Version: 1.0.0
 * Author: Custom
 * Text Domain: cf7-ecr
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (! defined('ABSPATH')) {
	exit;
}

final class CF7_ECR_Plugin
{
	const VERSION = '1.0.8';
	const DB_VERSION = '1.0.2';
	const OPTION_CAPACITY = 'cf7_ecr_capacity';
	const OPTION_DB_VERSION = 'cf7_ecr_db_version';
	const NONCE_SETTINGS = 'cf7_ecr_save_settings';
	const NONCE_DAY = 'cf7_ecr_save_day';
	const NONCE_FRONT = 'cf7_ecr_front';

	private static $instance = null;
	private $last_reservation = null;

	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
		add_action('plugins_loaded', array($this, 'maybe_upgrade'));
		add_action('admin_notices', array($this, 'admin_notice_missing_cf7'));

		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('admin_post_cf7_ecr_save_settings', array($this, 'handle_save_settings'));
		add_action('admin_post_cf7_ecr_save_day', array($this, 'handle_save_day'));
		add_action('admin_post_cf7_ecr_delete_day', array($this, 'handle_delete_day'));
		add_action('admin_post_cf7_ecr_delete_reservation', array($this, 'handle_delete_reservation'));

		add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
		add_action('wp_ajax_cf7_ecr_availability', array($this, 'ajax_availability'));
		add_action('wp_ajax_nopriv_cf7_ecr_availability', array($this, 'ajax_availability'));

		add_action('wpcf7_init', array($this, 'register_cf7_tags'));
		add_action('wpcf7_admin_init', array($this, 'register_cf7_tag_generators'), 30);
		add_filter('wpcf7_validate_ecr_date', array($this, 'validate_cf7_date'), 20, 2);
		add_filter('wpcf7_validate_ecr_date*', array($this, 'validate_cf7_date'), 20, 2);
		add_filter('wpcf7_validate_ecr_booking', array($this, 'validate_cf7_booking'), 20, 2);
		add_filter('wpcf7_validate_ecr_booking*', array($this, 'validate_cf7_booking'), 20, 2);
		add_action('wpcf7_before_send_mail', array($this, 'reserve_before_send_mail'), 9, 3);
		add_action('wpcf7_mail_failed', array($this, 'release_after_mail_failed'), 10, 1);
	}

	public static function activate()
	{
		self::create_or_update_tables();

		if (false === get_option(self::OPTION_CAPACITY, false)) {
			add_option(self::OPTION_CAPACITY, 10, '', false);
		}

		update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
	}

	public static function create_or_update_tables()
	{
		global $wpdb;

		$table = self::table_name();
		$reservations_table = self::reservations_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		date_key date NOT NULL,
		reserved_places int(10) unsigned NOT NULL DEFAULT 0,
		capacity int(10) unsigned NOT NULL DEFAULT 0,
		is_exclusive tinyint(1) unsigned NOT NULL DEFAULT 0,
		is_disabled tinyint(1) unsigned NOT NULL DEFAULT 0,
		customer_name varchar(190) NOT NULL DEFAULT '',
		customer_email varchar(190) NOT NULL DEFAULT '',
		customer_phone varchar(60) NOT NULL DEFAULT '',
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY date_key (date_key),
		KEY is_disabled (is_disabled),
		KEY is_exclusive (is_exclusive)
	) {$charset_collate};";

		dbDelta($sql);

		$reservations_sql = "CREATE TABLE {$reservations_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		date_key date NOT NULL,
		people int(10) unsigned NOT NULL DEFAULT 1,
		is_exclusive tinyint(1) unsigned NOT NULL DEFAULT 0,
		customer_name varchar(190) NOT NULL DEFAULT '',
		customer_email varchar(190) NOT NULL DEFAULT '',
		customer_phone varchar(60) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY date_key (date_key),
		KEY customer_email (customer_email),
		KEY created_at (created_at)
	) {$charset_collate};";

		dbDelta($reservations_sql);
	}

	public function maybe_upgrade()
	{
		if (get_option(self::OPTION_DB_VERSION) !== self::DB_VERSION) {
			self::create_or_update_tables();
			update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
		}
	}

	private static function table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'cf7_ecr_days';
	}

	private static function reservations_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'cf7_ecr_reservations';
	}

	public function admin_notice_missing_cf7()
	{
		if (! current_user_can('activate_plugins')) {
			return;
		}

		if (defined('WPCF7_VERSION')) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__('Rezerwacje rejsu wieczornego dla CF7 wymagają aktywnej wtyczki Contact Form 7.', 'cf7-ecr') . '</p></div>';
	}

	public function register_admin_menu()
	{
		$capability = 'manage_options';

		add_menu_page(
			esc_html__('Rejsy wieczorne', 'cf7-ecr'),
			esc_html__('Rejsy wieczorne', 'cf7-ecr'),
			$capability,
			'cf7-ecr-settings',
			array($this, 'render_settings_page'),
			'dashicons-calendar-alt',
			56
		);

		add_submenu_page(
			'cf7-ecr-settings',
			esc_html__('Ustawienia rejsów', 'cf7-ecr'),
			esc_html__('Ustawienia', 'cf7-ecr'),
			$capability,
			'cf7-ecr-settings',
			array($this, 'render_settings_page')
		);

		add_submenu_page(
			'cf7-ecr-settings',
			esc_html__('Rezerwacje', 'cf7-ecr'),
			esc_html__('Rezerwacje', 'cf7-ecr'),
			$capability,
			'cf7-ecr-reservations',
			array($this, 'render_reservations_page')
		);
	}

	public function enqueue_admin_assets($hook)
	{
		if (false === strpos((string) $hook, 'cf7-ecr')) {
			return;
		}

		wp_enqueue_style(
			'cf7-ecr-admin',
			plugins_url('assets/admin.css', __FILE__),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'cf7-ecr-admin',
			plugins_url('assets/admin.js', __FILE__),
			array(),
			self::VERSION,
			true
		);
	}

	public function enqueue_front_assets()
	{
		wp_enqueue_style(
			'cf7-ecr-front',
			plugins_url('assets/frontend.css', __FILE__),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'cf7-ecr-front',
			plugins_url('assets/frontend.js', __FILE__),
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			'cf7-ecr-front',
			'CF7ECR',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_FRONT),
				'capacity' => $this->get_capacity(),
				'today' => current_time('Y-m-d'),
				'minDate' => $this->get_min_booking_date(),
				'maxDate' => $this->get_max_booking_date(),
				'disabledDates' => $this->get_disabled_dates(),
				'texts' => array(
					'chooseDate' => esc_html__('Wybierz datę rejsu', 'cf7-ecr'),
					'noDate' => esc_html__('Nie wybrano daty rejsu.', 'cf7-ecr'),
					'selectedDate' => esc_html__('Termin rejsu:', 'cf7-ecr'),
					'loading' => esc_html__('Ładowanie dostępności...', 'cf7-ecr'),
					'noPlaces' => esc_html__('Brak wolnych miejsc.', 'cf7-ecr'),
					'disabledDate' => esc_html__('Ten dzień jest niedostępny.', 'cf7-ecr'),
					'places' => esc_html__('miejsc', 'cf7-ecr'),
					'place' => esc_html__('miejsce', 'cf7-ecr'),
					'people' => esc_html__('osób', 'cf7-ecr'),
					'person' => esc_html__('osoba', 'cf7-ecr'),
					'exclusive' => esc_html__('na wyłączność', 'cf7-ecr'),
					'summaryExclusive' => esc_html__('Na wyłączność', 'cf7-ecr'),
					'prevMonth' => esc_html__('Poprzedni miesiąc', 'cf7-ecr'),
					'nextMonth' => esc_html__('Następny miesiąc', 'cf7-ecr'),
					'selectDateFirst' => esc_html__('Najpierw wybierz datę rejsu.', 'cf7-ecr'),
				),
			)
		);
	}

	public function register_cf7_tags()
	{
		if (! function_exists('wpcf7_add_form_tag')) {
			return;
		}

		wpcf7_add_form_tag(
			array('ecr_date', 'ecr_date*'),
			array($this, 'render_cf7_date_tag'),
			array('name-attr' => true)
		);

		wpcf7_add_form_tag(
			array('ecr_booking', 'ecr_booking*'),
			array($this, 'render_cf7_booking_tag'),
			array('name-attr' => true)
		);
	}

	public function register_cf7_tag_generators()
	{
		if (! class_exists('WPCF7_TagGenerator')) {
			return;
		}

		$tag_generator = WPCF7_TagGenerator::get_instance();

		if (! method_exists($tag_generator, 'add')) {
			return;
		}

		$tag_generator->add(
			'ecr_date',
			esc_html__('Data rejsu', 'cf7-ecr'),
			array($this, 'render_date_tag_generator'),
			array('version' => '2')
		);

		$tag_generator->add(
			'ecr_booking',
			esc_html__('Rezerwacja rejsu', 'cf7-ecr'),
			array($this, 'render_booking_tag_generator'),
			array('version' => '2')
		);
	}

	public function render_date_tag_generator($contact_form, $options = array())
	{
		if (class_exists('WPCF7_TagGeneratorGenerator')) {
			$generator = new WPCF7_TagGeneratorGenerator($options['content'] ?? 'ecr-date');
			$generator->print(
				'field_type',
				array(
					'with_required' => true,
					'select_options' => array(
						'ecr_date' => esc_html__('Data rejsu', 'cf7-ecr'),
					),
				)
			);
			$generator->print('field_name');
			$generator->print('id_attr');
			$generator->print('class_attr');
			$generator->print('insert_box_content');
			$generator->print('mail_tag_tip');
			return;
		}

		$this->render_simple_generator_fallback('ecr_date', 'data-rejsu');
	}

	public function render_booking_tag_generator($contact_form, $options = array())
	{
		if (class_exists('WPCF7_TagGeneratorGenerator')) {
			$generator = new WPCF7_TagGeneratorGenerator($options['content'] ?? 'ecr-booking');
			$generator->print(
				'field_type',
				array(
					'with_required' => true,
					'select_options' => array(
						'ecr_booking' => esc_html__('Rezerwacja rejsu', 'cf7-ecr'),
					),
				)
			);
			$generator->print('field_name');
			$generator->print('id_attr');
			$generator->print('class_attr');
			$generator->print('insert_box_content');
			$generator->print('mail_tag_tip');
			return;
		}

		$this->render_simple_generator_fallback('ecr_booking', 'rezerwacja-rejsu');
	}

	private function render_simple_generator_fallback($type, $name)
	{
?>
		<fieldset>
			<legend><?php echo esc_html__('Kod pola', 'cf7-ecr'); ?></legend>
			<input type="text" class="code" readonly value="[<?php echo esc_attr($type); ?>* <?php echo esc_attr($name); ?>]">
		</fieldset>
	<?php
	}

	public function render_cf7_date_tag($tag)
	{
		$tag = new WPCF7_FormTag($tag);

		if (empty($tag->name)) {
			return '';
		}

		$validation_error = function_exists('wpcf7_get_validation_error') ? wpcf7_get_validation_error($tag->name) : '';
		$class = function_exists('wpcf7_form_controls_class') ? wpcf7_form_controls_class($tag->type, 'cf7-ecr-date-value') : 'cf7-ecr-date-value';

		if ($validation_error) {
			$class .= ' wpcf7-not-valid';
		}

		$atts = array(
			'type' => 'hidden',
			'name' => $tag->name,
			'value' => '',
			'class' => $class,
			'aria-required' => $tag->is_required() ? 'true' : 'false',
			'aria-invalid' => $validation_error ? 'true' : 'false',
		);

		$id = $tag->get_id_option();

		if ($id) {
			$atts['id'] = $id;
		}

		$atts = wpcf7_format_atts($atts);
		$wrap_name = sanitize_html_class($tag->name);
		$unique_id = function_exists('wp_unique_id') ? wp_unique_id('cf7-ecr-date-') : 'cf7-ecr-date-' . wp_rand(1000, 999999);

		$html = '<span class="wpcf7-form-control-wrap cf7-ecr-wrap cf7-ecr-date-wrap" data-name="' . esc_attr($tag->name) . '">';
		$html .= '<span class="cf7-ecr-date" data-field-name="' . esc_attr($tag->name) . '" data-calendar-id="' . esc_attr($unique_id) . '">';
		$html .= '<input ' . $atts . ' />';
		$html .= '<input type="hidden" name="_ecr_date" class="cf7-ecr-fixed-date" value="" />';
		$html .= $validation_error;
		$html .= '<span class="cf7-ecr-date-selected">' . esc_html__('Nie wybrano daty rejsu.', 'cf7-evening-cruise-reservations') . '</span>';
		$html .= '<span id="' . esc_attr($unique_id) . '" class="cf7-ecr-calendar"></span>';
		$html .= '<span class="data-head">Wybierz datę rejsu</span>';
		$html .= '</span></span>';

		return $html;
	}

	public function render_cf7_booking_tag($tag)
	{
		$tag = new WPCF7_FormTag($tag);

		if (empty($tag->name)) {
			return '';
		}

		$capacity = $this->get_capacity();
		$validation_error = function_exists('wpcf7_get_validation_error') ? wpcf7_get_validation_error($tag->name) : '';
		$class = function_exists('wpcf7_form_controls_class') ? wpcf7_form_controls_class($tag->type, 'cf7-ecr-booking-value') : 'cf7-ecr-booking-value';

		if ($validation_error) {
			$class .= ' wpcf7-not-valid';
		}

		$atts = array(
			'type' => 'hidden',
			'name' => $tag->name,
			'value' => '1 osoba',
			'class' => $class,
			'aria-required' => $tag->is_required() ? 'true' : 'false',
			'aria-invalid' => $validation_error ? 'true' : 'false',
		);

		$id = $tag->get_id_option();

		if ($id) {
			$atts['id'] = $id;
		}

		$atts = wpcf7_format_atts($atts);
		$people_id = function_exists('wp_unique_id') ? wp_unique_id('cf7-ecr-people-') : 'cf7-ecr-people-' . wp_rand(1000, 999999);
		$exclusive_id = function_exists('wp_unique_id') ? wp_unique_id('cf7-ecr-exclusive-') : 'cf7-ecr-exclusive-' . wp_rand(1000, 999999);

		$html = '<span class="wpcf7-form-control-wrap cf7-ecr-wrap cf7-ecr-booking-wrap" data-name="' . esc_attr($tag->name) . '">';
		$html .= '<span class="cf7-ecr-booking" data-field-name="' . esc_attr($tag->name) . '" data-capacity="' . esc_attr($capacity) . '">';
		$html .= '<input ' . $atts . ' />';
		$html .= '<input type="hidden" name="_ecr_people" class="cf7-ecr-fixed-people" value="1" />';
		$html .= '<input type="hidden" name="_ecr_exclusive" class="cf7-ecr-fixed-exclusive" value="0" />';
		$html .= '<label class="cf7-ecr-exclusive-label big-text" for="' . esc_attr($exclusive_id) . '">';
		$html .= '<input type="checkbox" id="' . esc_attr($exclusive_id) . '" class="cf7-ecr-exclusive" value="1" /> ';
		$html .= '<strong>' . esc_html__('Rejs na wyłączność', 'cf7-ecr') . '</strong> ';
		$html .= esc_html__('(cały jacht tylko dla Was)', 'cf7-ecr');
		$html .= '</label>';
		$html .= '<label class="cf7-ecr-people-label" for="' . esc_attr($people_id) . '">';
		$html .= '<span class="no-margin">' . esc_html__('Liczba uczestników', 'cf7-ecr') . '</span>';
		$html .= '<input type="number" id="' . esc_attr($people_id) . '" class="cf7-ecr-people" min="1" max="' . esc_attr($capacity) . '" step="1" value="1" inputmode="numeric" />';
		$html .= '</label>';
		$html .= $validation_error;
		$html .= '</span></span>';

		return $html;
	}

	public function validate_cf7_date($result, $tag)
	{
		$tag = new WPCF7_FormTag($tag);
		$date = $this->get_posted_text('_ecr_date');

		if ('' === $date) {
			$date = $this->get_posted_text($tag->name);
		}

		if ('' === $date) {
			$result->invalidate($tag, esc_html__('Wybierz datę rejsu.', 'cf7-ecr'));
			return $result;
		}

		if (! $this->is_valid_date($date)) {
			$result->invalidate($tag, esc_html__('Podana data ma nieprawidłowy format.', 'cf7-ecr'));
			return $result;
		}

		if ($date < current_time('Y-m-d')) {
			$result->invalidate($tag, esc_html__('Nie można wybrać minionego dnia.', 'cf7-ecr'));
			return $result;
		}
		if ($date < $this->get_min_booking_date()) {
			$result->invalidate($tag, esc_html__('Rezerwacje są dostępne tylko od początku czerwca.', 'cf7-ecr'));
			return $result;
		}

		if ($date > $this->get_max_booking_date()) {
			$result->invalidate($tag, esc_html__('Rezerwacje są dostępne tylko do końca sierpnia.', 'cf7-ecr'));
			return $result;
		}

		$status = $this->get_date_status($date);

		if (! empty($status['is_disabled'])) {
			$result->invalidate($tag, esc_html__('Wybrany dzień jest wyłączony z rezerwacji.', 'cf7-ecr'));
		}

		return $result;
	}

	public function validate_cf7_booking($result, $tag)
	{
		$tag = new WPCF7_FormTag($tag);
		$request = $this->get_booking_request_from_post();

		if ('' === $request['date']) {
			return $result;
		}

		$validation = $this->validate_booking_request($request['date'], $request['people'], $request['exclusive']);

		if (is_wp_error($validation)) {
			$result->invalidate($tag, $validation->get_error_message());
		}

		return $result;
	}

	public function reserve_before_send_mail($contact_form, &$abort = false, $submission = null)
	{
		if (! $this->contact_form_has_ecr_tags($contact_form)) {
			return;
		}

		$request = $this->get_booking_request_from_post();

		if ('' === $request['date'] && 0 === $request['people'] && ! $request['exclusive']) {
			return;
		}

		$validation = $this->validate_booking_request($request['date'], $request['people'], $request['exclusive']);

		if (is_wp_error($validation)) {
			$abort = true;
			$this->set_cf7_abort_response($submission, $validation->get_error_message());
			return;
		}

		$reserved = $this->reserve_places(
			$request['date'],
			$request['people'],
			$request['exclusive'],
			$request['customer_name'],
			$request['customer_email'],
			$request['customer_phone']
		);

		if (is_wp_error($reserved)) {
			$abort = true;
			$this->set_cf7_abort_response($submission, $reserved->get_error_message());
			return;
		}

		$this->last_reservation = array(
			'reservation_id' => absint($reserved),
			'date' => $request['date'],
			'people' => $request['people'],
			'exclusive' => $request['exclusive'],
			'customer_name' => $request['customer_name'],
			'customer_email' => $request['customer_email'],
			'customer_phone' => $request['customer_phone'],
		);
	}

	public function release_after_mail_failed($contact_form)
	{
		if (empty($this->last_reservation)) {
			return;
		}

		$this->release_places(
			$this->last_reservation['date'],
			$this->last_reservation['people'],
			$this->last_reservation['exclusive'],
			isset($this->last_reservation['reservation_id']) ? absint($this->last_reservation['reservation_id']) : 0
		);

		$this->last_reservation = null;
	}

	private function set_cf7_abort_response($submission, $message)
	{
		if (is_object($submission) && is_callable(array($submission, 'set_response'))) {
			$submission->set_response($message);
		}

		if (is_object($submission) && is_callable(array($submission, 'set_status'))) {
			$submission->set_status('validation_failed');
		}
	}


	private function contact_form_has_ecr_tags($contact_form)
	{
		if (! is_object($contact_form)) {
			return false;
		}

		$tags = array();

		if (method_exists($contact_form, 'scan_form_tags')) {
			$tags = $contact_form->scan_form_tags();
		} elseif (method_exists($contact_form, 'form_scan_shortcode')) {
			$tags = $contact_form->form_scan_shortcode();
		}

		foreach ((array) $tags as $tag) {
			$type = '';

			if (is_object($tag) && isset($tag->type)) {
				$type = (string) $tag->type;
			} elseif (is_array($tag) && isset($tag['type'])) {
				$type = (string) $tag['type'];
			}

			if (in_array($type, array('ecr_date', 'ecr_date*', 'ecr_booking', 'ecr_booking*'), true)) {
				return true;
			}
		}

		return false;
	}

	private function get_booking_request_from_post()
	{
		$date = $this->get_posted_text('_ecr_date');
		$people = absint($this->get_posted_text('_ecr_people'));
		$exclusive = '1' === $this->get_posted_text('_ecr_exclusive');

		if ($exclusive) {
			$people = $this->get_capacity();
		}

		return array(
			'date' => $date,
			'people' => $people,
			'exclusive' => $exclusive,
			'customer_name' => $this->get_posted_text('your-name'),
			'customer_email' => sanitize_email($this->get_posted_text('your-email')),
			'customer_phone' => $this->get_posted_text('tel-782'),
		);
	}

	private function validate_booking_request($date, $people, $exclusive)
	{
		$capacity = $this->get_capacity();

		if ('' === $date) {
			return new WP_Error('cf7_ecr_no_date', esc_html__('Wybierz datę rejsu.', 'cf7-ecr'));
		}

		if (! $this->is_valid_date($date)) {
			return new WP_Error('cf7_ecr_bad_date', esc_html__('Podana data ma nieprawidłowy format.', 'cf7-ecr'));
		}

		if ($date < current_time('Y-m-d')) {
			return new WP_Error('cf7_ecr_past_date', esc_html__('Nie można wybrać minionego dnia.', 'cf7-ecr'));
		}

		if ($date < $this->get_min_booking_date()) {
			return new WP_Error(
				'cf7_ecr_before_min_date',
				esc_html__('Rezerwacje są dostępne tylko od początku czerwca.', 'cf7-ecr')
			);
		}

		if ($date > $this->get_max_booking_date()) {
			return new WP_Error(
				'cf7_ecr_after_max_date',
				esc_html__('Rezerwacje są dostępne tylko do końca sierpnia.', 'cf7-ecr')
			);
		}

		if ($exclusive) {
			$people = $capacity;
		} elseif ($people < 1) {
			return new WP_Error('cf7_ecr_no_people', esc_html__('Podaj liczbę osób.', 'cf7-ecr'));
		} elseif ($people > $capacity) {
			return new WP_Error('cf7_ecr_too_many_people', sprintf(esc_html__('Maksymalna liczba miejsc to %d.', 'cf7-ecr'), absint($capacity)));
		}

		$status = $this->get_date_status($date);

		if (! empty($status['is_disabled'])) {
			return new WP_Error('cf7_ecr_disabled', esc_html__('Wybrany dzień jest wyłączony z rezerwacji.', 'cf7-ecr'));
		}

		if (! empty($status['is_exclusive'])) {
			return new WP_Error('cf7_ecr_exclusive_exists', esc_html__('Ten dzień jest już zarezerwowany na wyłączność.', 'cf7-ecr'));
		}

		if ($exclusive && absint($status['reserved_places']) > 0) {
			return new WP_Error(
				'cf7_ecr_exclusive_not_empty',
				esc_html__('Rezerwacja na wyłączność jest możliwa tylko dla dnia bez wcześniejszych rezerwacji.', 'cf7-ecr')
			);
		}

		if (! $exclusive && absint($status['available_places']) < $people) {
			return new WP_Error('cf7_ecr_not_enough_places', esc_html__('Na ten dzień nie ma tylu wolnych miejsc.', 'cf7-ecr'));
		}

		return true;
	}

	private function reserve_places($date, $people, $exclusive, $customer_name = '', $customer_email = '', $customer_phone = '')
	{
		global $wpdb;

		$capacity = $this->get_capacity();
		$table = self::table_name();
		$reservations_table = self::reservations_table_name();
		$now = current_time('mysql');

		$customer_name = sanitize_text_field($customer_name);
		$customer_email = sanitize_email($customer_email);
		$customer_phone = sanitize_text_field($customer_phone);

		$this->ensure_day_row($date);

		if ($exclusive) {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
			SET reserved_places = %d, capacity = %d, is_exclusive = 1, updated_at = %s
			WHERE date_key = %s AND is_disabled = 0 AND is_exclusive = 0 AND reserved_places = 0",
				$capacity,
				$capacity,
				$now,
				$date
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
			SET reserved_places = reserved_places + %d, capacity = %d, is_exclusive = 0, updated_at = %s
			WHERE date_key = %s AND is_disabled = 0 AND is_exclusive = 0 AND (reserved_places + %d) <= %d",
				$people,
				$capacity,
				$now,
				$date,
				$people,
				$capacity
			);
		}

		$updated = $wpdb->query($sql);

		if (false === $updated) {
			return new WP_Error('cf7_ecr_db_error', esc_html__('Nie udało się zapisać rezerwacji. Spróbuj ponownie.', 'cf7-ecr'));
		}

		if (1 !== absint($updated)) {
			if ($exclusive) {
				return new WP_Error(
					'cf7_ecr_exclusive_not_available',
					esc_html__('Rezerwacja na wyłączność nie jest już dostępna dla wybranego dnia.', 'cf7-ecr')
				);
			}

			return new WP_Error(
				'cf7_ecr_race_condition',
				esc_html__('Wybrany dzień nie jest już dostępny w wybranej liczbie miejsc.', 'cf7-ecr')
			);
		}

		$inserted = $wpdb->insert(
			$reservations_table,
			array(
				'date_key' => $date,
				'people' => $exclusive ? $capacity : absint($people),
				'is_exclusive' => $exclusive ? 1 : 0,
				'customer_name' => $customer_name,
				'customer_email' => $customer_email,
				'customer_phone' => $customer_phone,
				'created_at' => $now,
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if (false === $inserted) {
			$this->release_places($date, $people, $exclusive);

			return new WP_Error(
				'cf7_ecr_reservation_insert_error',
				esc_html__('Nie udało się zapisać danych rezerwacji. Spróbuj ponownie.', 'cf7-ecr')
			);
		}

		return absint($wpdb->insert_id);
	}

	private function release_places($date, $people, $exclusive, $reservation_id = 0)
	{
		global $wpdb;

		$table = self::table_name();
		$reservations_table = self::reservations_table_name();
		$now = current_time('mysql');

		if ($exclusive) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
				SET reserved_places = 0, is_exclusive = 0, updated_at = %s
				WHERE date_key = %s",
					$now,
					$date
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
				SET reserved_places = GREATEST(reserved_places - %d, 0),
					is_exclusive = 0,
					updated_at = %s
				WHERE date_key = %s",
					absint($people),
					$now,
					$date
				)
			);
		}

		if ($reservation_id > 0) {
			$wpdb->delete(
				$reservations_table,
				array('id' => absint($reservation_id)),
				array('%d')
			);
		}
	}

	private function ensure_day_row($date)
	{
		global $wpdb;

		$table = self::table_name();
		$capacity = $this->get_capacity();
		$now = current_time('mysql');

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (date_key, reserved_places, capacity, is_exclusive, is_disabled, updated_at) VALUES (%s, 0, %d, 0, 0, %s)",
				$date,
				$capacity,
				$now
			)
		);
	}

	public function ajax_availability()
	{
		check_ajax_referer(self::NONCE_FRONT, 'nonce');

		$start = $this->get_posted_text('start');
		$end = $this->get_posted_text('end');

		if (! $this->is_valid_date($start) || ! $this->is_valid_date($end) || $end < $start) {
			wp_send_json_error(
				array('message' => esc_html__('Nieprawidłowy zakres dat.', 'cf7-ecr')),
				400
			);
		}

		$max_days = 93;
		$start_dt = new DateTimeImmutable($start);
		$end_dt = new DateTimeImmutable($end);

		if ($start_dt->diff($end_dt)->days > $max_days) {
			wp_send_json_error(
				array('message' => esc_html__('Zakres dat jest zbyt szeroki.', 'cf7-ecr')),
				400
			);
		}

		$rows = $this->get_rows_between($start, $end);
		$capacity = $this->get_capacity();
		$today = current_time('Y-m-d');
		$min_date = $this->get_min_booking_date();
		$max_date = $this->get_max_booking_date();
		$dates = array();

		$cursor = $start_dt;
		while ($cursor <= $end_dt) {
			$date = $cursor->format('Y-m-d');
			$row = isset($rows[$date]) ? $rows[$date] : null;
			$reserved = $row ? absint($row->reserved_places) : 0;
			$is_disabled = $row ? (bool) absint($row->is_disabled) : false;
			$is_exclusive = $row ? (bool) absint($row->is_exclusive) : false;
			$available_places = max(0, $capacity - $reserved);
			$is_past = $date < $today;
			$is_before_min = $date < $min_date;
			$is_after_max = $date > $max_date;

			$dates[$date] = array(
				'date' => $date,
				'capacity' => $capacity,
				'reserved_places' => $reserved,
				'available_places' => $available_places,
				'is_disabled' => $is_disabled,
				'is_exclusive' => $is_exclusive,
				'is_past' => $is_past,
				'is_before_min' => $is_before_min,
				'is_after_max' => $is_after_max,
				'available' => ! $is_past && ! $is_before_min && ! $is_after_max && ! $is_disabled && ! $is_exclusive && $available_places > 0,
			);

			$cursor = $cursor->modify('+1 day');
		}

		wp_send_json_success(
			array(
				'capacity' => $capacity,
				'dates' => $dates,
			)
		);
	}

	private function get_rows_between($start, $end)
	{
		global $wpdb;

		$table = self::table_name();
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE date_key BETWEEN %s AND %s",
				$start,
				$end
			)
		);

		$rows = array();
		foreach ((array) $items as $item) {
			$rows[$item->date_key] = $item;
		}

		return $rows;
	}

	private function get_date_status($date)
	{
		global $wpdb;

		$table = self::table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE date_key = %s LIMIT 1",
				$date
			)
		);

		$capacity = $this->get_capacity();
		$reserved = $row ? absint($row->reserved_places) : 0;
		$is_disabled = $row ? (bool) absint($row->is_disabled) : false;
		$is_exclusive = $row ? (bool) absint($row->is_exclusive) : false;
		$available_places = max(0, $capacity - $reserved);
		$is_before_min = $date < $this->get_min_booking_date();
		$is_after_max = $date > $this->get_max_booking_date();

		return array(
			'date' => $date,
			'capacity' => $capacity,
			'reserved_places' => $reserved,
			'available_places' => $available_places,
			'is_disabled' => $is_disabled,
			'is_exclusive' => $is_exclusive,
			'is_before_min' => $is_before_min,
			'is_after_max' => $is_after_max,
			'available' => ! $is_before_min && ! $is_after_max && ! $is_disabled && ! $is_exclusive && $available_places > 0,
		);
	}

	private function get_capacity()
	{
		$capacity = absint(get_option(self::OPTION_CAPACITY, 10));
		return max(1, $capacity);
	}
	private function get_booking_year()
	{
		$year = absint(current_time('Y'));
		$today = current_time('Y-m-d');

		if ($today > $year . '-08-31') {
			$year++;
		}

		return $year;
	}

	private function get_min_booking_date()
	{
		return $this->get_booking_year() . '-06-01';
	}

	private function get_max_booking_date()
	{
		return $this->get_booking_year() . '-08-31';
	}

	private function get_posted_text($key)
	{
		if (! isset($_POST[$key])) {
			return '';
		}

		$value = wp_unslash($_POST[$key]);

		if (is_array($value)) {
			$value = reset($value);
		}

		return sanitize_text_field((string) $value);
	}

	private function is_valid_date($date)
	{
		if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return false;
		}

		$parts = explode('-', $date);
		return checkdate(absint($parts[1]), absint($parts[2]), absint($parts[0]));
	}

	public function render_settings_page()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		$capacity = $this->get_capacity();
		$disabled_dates = $this->get_disabled_dates();
	?>
		<div class="wrap cf7-ecr-admin-page">
			<h1><?php echo esc_html__('Rejsy wieczorne', 'cf7-ecr'); ?></h1>

			<?php $this->render_admin_notice_from_query(); ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cf7-ecr-card">
				<input type="hidden" name="action" value="cf7_ecr_save_settings">
				<?php wp_nonce_field(self::NONCE_SETTINGS); ?>

				<h2><?php echo esc_html__('Ustawienia', 'cf7-ecr'); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="cf7-ecr-capacity"><?php echo esc_html__('Liczba miejsc na rejs', 'cf7-ecr'); ?></label></th>
							<td>
								<input type="number" id="cf7-ecr-capacity" name="capacity" min="1" step="1" value="<?php echo esc_attr($capacity); ?>" class="small-text">
								<p class="description"><?php echo esc_html__('Ta wartość określa maksymalną liczbę miejsc dostępnych dla jednego dnia.', 'cf7-ecr'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7-ecr-disabled-date-new"><?php echo esc_html__('Wyłączone dni', 'cf7-ecr'); ?></label></th>
							<td>
								<div class="cf7-ecr-date-adder">
									<input type="date" id="cf7-ecr-disabled-date-new">
									<button type="button" class="button" id="cf7-ecr-add-disabled-date"><?php echo esc_html__('Dodaj dzień', 'cf7-ecr'); ?></button>
								</div>
								<textarea id="cf7-ecr-disabled-dates" name="disabled_dates" rows="10" class="large-text code" placeholder="2026-07-15&#10;2026-07-20"><?php echo esc_textarea(implode("\n", $disabled_dates)); ?></textarea>
								<p class="description"><?php echo esc_html__('Jedna data w wierszu, format RRRR-MM-DD. Te dni nie będą możliwe do wyboru w kalendarzu na stronie.', 'cf7-ecr'); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(esc_html__('Zapisz ustawienia', 'cf7-ecr')); ?>
			</form>

			<div class="cf7-ecr-card">
				<h2><?php echo esc_html__('Pola Contact Form 7', 'cf7-ecr'); ?></h2>
				<p><?php echo esc_html__('W edytorze formularza CF7 dodaj dwa pola z generatora: „Data rejsu” oraz „Rezerwacja rejsu”. Możesz też wkleić poniższy kod ręcznie:', 'cf7-ecr'); ?></p>
				<pre><code>[ecr_date* data-rejsu]
[ecr_booking* rezerwacja-rejsu]</code></pre>
				<p><?php echo esc_html__('W zakładce Mail formularza dodaj tagi:', 'cf7-ecr'); ?></p>
				<pre><code><?php echo esc_html__('Data rejsu:', 'cf7-ecr'); ?> [data-rejsu]
<?php echo esc_html__('Rezerwacja:', 'cf7-ecr'); ?> [rezerwacja-rejsu]</code></pre>
			</div>
		</div>
	<?php
	}

	public function render_reservations_page()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		$capacity = $this->get_capacity();
		$rows = $this->get_admin_rows();
	?>
		<div class="wrap cf7-ecr-admin-page">
			<h1><?php echo esc_html__('Rezerwacje', 'cf7-ecr'); ?></h1>

			<?php $this->render_admin_notice_from_query(); ?>

			<div class="cf7-ecr-card">
				<h2><?php echo esc_html__('Edytuj dzień', 'cf7-ecr'); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cf7-ecr-edit-day-form">
					<input type="hidden" name="action" value="cf7_ecr_save_day">
					<?php wp_nonce_field(self::NONCE_DAY); ?>

					<p>
						<label for="cf7-ecr-day-date"><strong><?php echo esc_html__('Data', 'cf7-ecr'); ?></strong></label><br>
						<input type="date" id="cf7-ecr-day-date" name="date_key" required>
					</p>

					<p>
						<label for="cf7-ecr-day-reserved"><strong><?php echo esc_html__('Zarezerwowane miejsca', 'cf7-ecr'); ?></strong></label><br>
						<input type="number" id="cf7-ecr-day-reserved" name="reserved_places" min="0" max="<?php echo esc_attr($capacity); ?>" step="1" value="0" class="small-text">
						<span class="description"><?php echo sprintf(esc_html__('Maksymalnie %d.', 'cf7-ecr'), absint($capacity)); ?></span>
					</p>

					<p>
						<label><input type="checkbox" name="is_exclusive" value="1"> <?php echo esc_html__('Rezerwacja na wyłączność', 'cf7-ecr'); ?></label><br>

					</p>

					<?php submit_button(esc_html__('Zapisz dzień', 'cf7-ecr'), 'primary', 'submit', false); ?>
				</form>
			</div>

			<div class="cf7-ecr-card">
				<h2><?php echo esc_html__('Zapisane rezerwacje', 'cf7-ecr'); ?></h2>
				<p><?php echo esc_html__('Lista pokazuje każdą rezerwację osobno, nawet jeśli kilka rezerwacji dotyczy tego samego dnia.', 'cf7-ecr'); ?></p>

				<?php if (empty($rows)) : ?>
					<p><?php echo esc_html__('Brak zapisanych rezerwacji.', 'cf7-ecr'); ?></p>
				<?php else : ?>
					<table class="widefat striped cf7-ecr-days-table">
						<thead>
							<tr>
								<th><?php echo esc_html__('Data', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Liczba osób', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Zajęte miejsca w dniu', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Wolne miejsca', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Imię i nazwisko', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('E-mail', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Telefon', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Na wyłączność', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Data rezerwacji', 'cf7-ecr'); ?></th>
								<th><?php echo esc_html__('Akcje', 'cf7-ecr'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $row) : ?>
								<?php
								$reserved = absint($row->reserved_places);
								$row_capacity = ! empty($row->capacity) ? absint($row->capacity) : $capacity;
								$free = max(0, $row_capacity - $reserved);
								$people = absint($row->people);
								?>
								<tr>
									<td><strong><?php echo esc_html($row->date_key); ?></strong></td>

									<td><?php echo esc_html($people); ?></td>

									<td><?php echo esc_html($reserved . ' / ' . $row_capacity); ?></td>

									<td><?php echo esc_html($free); ?></td>

									<td><?php echo ! empty($row->customer_name) ? esc_html($row->customer_name) : '—'; ?></td>

									<td>
										<?php if (! empty($row->customer_email)) : ?>
											<a href="mailto:<?php echo esc_attr($row->customer_email); ?>"><?php echo esc_html($row->customer_email); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>

									<td>
										<?php if (! empty($row->customer_phone)) : ?>
											<a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $row->customer_phone)); ?>"><?php echo esc_html($row->customer_phone); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>

									<td><?php echo $row->is_exclusive ? esc_html__('Tak', 'cf7-ecr') : esc_html__('Nie', 'cf7-ecr'); ?></td>

									<td><?php echo esc_html($row->created_at); ?></td>

									<td>
										<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cf7-ecr-inline-form">
											<input type="hidden" name="action" value="cf7_ecr_delete_reservation">
											<input type="hidden" name="reservation_id" value="<?php echo esc_attr(absint($row->id)); ?>">
											<?php wp_nonce_field(self::NONCE_DAY); ?>
											<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Usunąć tę rezerwację?', 'cf7-ecr')); ?>');">
												<?php echo esc_html__('Usuń', 'cf7-ecr'); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
<?php
	}

	private function render_admin_notice_from_query()
	{
		if (empty($_GET['cf7_ecr_status'])) {
			return;
		}

		$status = sanitize_key(wp_unslash($_GET['cf7_ecr_status']));
		$message = '';
		$class = 'notice notice-success is-dismissible';

		switch ($status) {
			case 'saved':
				$message = esc_html__('Zapisano zmiany.', 'cf7-ecr');
				break;
			case 'deleted':
				$message = esc_html__('Wyczyszczono dzień.', 'cf7-ecr');
				break;
			case 'error':
				$message = esc_html__('Nie udało się zapisać zmian.', 'cf7-ecr');
				$class = 'notice notice-error is-dismissible';
				break;
		}

		if ($message) {
			echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
		}
	}

	public function handle_save_settings()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		check_admin_referer(self::NONCE_SETTINGS);

		$capacity = isset($_POST['capacity']) ? absint(wp_unslash($_POST['capacity'])) : 1;
		$capacity = max(1, $capacity);
		update_option(self::OPTION_CAPACITY, $capacity, false);

		$disabled_raw = isset($_POST['disabled_dates']) ? (string) wp_unslash($_POST['disabled_dates']) : '';
		$disabled_dates = $this->sanitize_date_lines($disabled_raw);
		$this->sync_disabled_dates($disabled_dates);

		wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-settings&cf7_ecr_status=saved'));
		exit;
	}

	public function handle_save_day()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		check_admin_referer(self::NONCE_DAY);

		$date = isset($_POST['date_key']) ? sanitize_text_field(wp_unslash($_POST['date_key'])) : '';

		if (! $this->is_valid_date($date)) {
			wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=error'));
			exit;
		}

		$capacity = $this->get_capacity();
		$reserved = isset($_POST['reserved_places']) ? absint(wp_unslash($_POST['reserved_places'])) : 0;
		$is_exclusive = isset($_POST['is_exclusive']) ? 1 : 0;
		$is_disabled = isset($_POST['is_disabled']) ? 1 : 0;

		if ($is_exclusive) {
			$reserved = $capacity;
		} else {
			$reserved = min($reserved, $capacity);
		}

		$this->ensure_day_row($date);
		$this->update_day_row($date, $reserved, $capacity, $is_exclusive, $is_disabled);

		wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=saved'));
		exit;
	}

	public function handle_delete_day()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		check_admin_referer(self::NONCE_DAY);

		$date = isset($_POST['date_key']) ? sanitize_text_field(wp_unslash($_POST['date_key'])) : '';

		if ($this->is_valid_date($date)) {
			global $wpdb;

			$wpdb->delete(self::table_name(), array('date_key' => $date), array('%s'));

			$wpdb->delete(
				self::reservations_table_name(),
				array('date_key' => $date),
				array('%s')
			);
		}

		wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=deleted'));
		exit;
	}

	public function handle_delete_reservation()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Brak uprawnień.', 'cf7-ecr'));
		}

		check_admin_referer(self::NONCE_DAY);

		$reservation_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

		if ($reservation_id <= 0) {
			wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=error'));
			exit;
		}

		global $wpdb;

		$reservations_table = self::reservations_table_name();

		$reservation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$reservations_table} WHERE id = %d LIMIT 1",
				$reservation_id
			)
		);

		if (! $reservation) {
			wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=error'));
			exit;
		}

		$this->release_places(
			$reservation->date_key,
			absint($reservation->people),
			(bool) absint($reservation->is_exclusive),
			$reservation_id
		);

		wp_safe_redirect(admin_url('admin.php?page=cf7-ecr-reservations&cf7_ecr_status=deleted'));
		exit;
	}
	private function update_day_row($date, $reserved, $capacity, $is_exclusive, $is_disabled)
	{
		global $wpdb;

		$data = array(
			'reserved_places' => absint($reserved),
			'capacity' => absint($capacity),
			'is_exclusive' => absint($is_exclusive),
			'is_disabled' => absint($is_disabled),
			'updated_at' => current_time('mysql'),
		);

		$formats = array('%d', '%d', '%d', '%d', '%s');

		if (0 === absint($reserved)) {
			$data['customer_name'] = '';
			$data['customer_email'] = '';
			$data['customer_phone'] = '';
			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%s';
		}

		$wpdb->update(
			self::table_name(),
			$data,
			array('date_key' => $date),
			$formats,
			array('%s')
		);
	}

	private function sync_disabled_dates($dates)
	{
		global $wpdb;

		$table = self::table_name();
		$capacity = $this->get_capacity();
		$now = current_time('mysql');

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_disabled = 0, updated_at = %s WHERE is_disabled = 1",
				$now
			)
		);

		foreach ($dates as $date) {
			$this->ensure_day_row($date);
			$wpdb->update(
				$table,
				array(
					'is_disabled' => 1,
					'capacity' => $capacity,
					'updated_at' => $now,
				),
				array('date_key' => $date),
				array('%d', '%d', '%s'),
				array('%s')
			);
		}
	}

	private function sanitize_date_lines($raw)
	{
		$lines = preg_split('/\R/', (string) $raw);
		$dates = array();

		foreach ($lines as $line) {
			$date = trim(sanitize_text_field($line));
			if ($this->is_valid_date($date)) {
				$dates[$date] = $date;
			}
		}

		ksort($dates);
		return array_values($dates);
	}

	private function get_disabled_dates()
	{
		global $wpdb;

		$table = self::table_name();
		$dates = $wpdb->get_col("SELECT date_key FROM {$table} WHERE is_disabled = 1 ORDER BY date_key ASC");

		return array_map('sanitize_text_field', (array) $dates);
	}

	private function get_admin_rows()
	{
		global $wpdb;

		$reservations_table = self::reservations_table_name();
		$days_table = self::table_name();

		return $wpdb->get_results(
			"SELECT 
			r.id,
			r.date_key,
			r.people,
			r.is_exclusive,
			r.customer_name,
			r.customer_email,
			r.customer_phone,
			r.created_at,
			d.reserved_places,
			d.capacity,
			d.is_disabled
		FROM {$reservations_table} r
		LEFT JOIN {$days_table} d ON d.date_key = r.date_key
		ORDER BY r.created_at DESC
		LIMIT 300"
		);
	}
}

register_activation_hook(__FILE__, array('CF7_ECR_Plugin', 'activate'));
CF7_ECR_Plugin::instance();
