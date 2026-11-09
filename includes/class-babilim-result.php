<?php
/**
 * A simple outcome value object: either success (with a data payload) or
 * failure (with an error code and message). Returned by every
 * Babilim_Installer/Babilim_Default_Index operation instead of throwing,
 * so a caller (the sync/cron flow) can inspect what went wrong without
 * wrapping every call in a try/catch.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Success/failure outcome with an attached data payload.
 */
final class Babilim_Result {

	/**
	 * @var bool
	 */
	private $ok;

	/**
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * @var string|null
	 */
	private $code;

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @param bool                $ok      Whether the operation succeeded.
	 * @param array<string,mixed> $data    Payload.
	 * @param string|null         $code    Machine-readable error code; null on success.
	 * @param string              $message Human-readable message; '' on success.
	 */
	private function __construct( bool $ok, array $data, ?string $code, string $message ) {
		$this->ok      = $ok;
		$this->data    = $data;
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * A successful result.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @return self
	 */
	public static function ok( array $data = array() ): self {
		return new self( true, $data, null, '' );
	}

	/**
	 * A failed result.
	 *
	 * @param string               $code    Machine-readable error code.
	 * @param string               $message Human-readable message.
	 * @param array<string,mixed>  $data    Extra payload (e.g. partial diagnostics).
	 * @return self
	 */
	public static function error( string $code, string $message = '', array $data = array() ): self {
		return new self( false, $data, $code, $message );
	}

	/**
	 * @return bool
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * @return bool
	 */
	public function is_error(): bool {
		return ! $this->ok;
	}

	/**
	 * @return string|null Null on success.
	 */
	public function code(): ?string {
		return $this->code;
	}

	/**
	 * @return string Empty string on success.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * One value out of data(), or $default if absent.
	 *
	 * @param string $key     Data key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}
}
