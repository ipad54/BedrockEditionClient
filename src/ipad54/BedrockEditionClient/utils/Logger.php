<?php

namespace ipad54\BedrockEditionClient\utils;

use LogLevel;
use pocketmine\utils\Terminal;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;

class Logger implements \Logger{

	protected bool $logDebug;

	private string $format = TextFormat::AQUA . "[%s] " . TextFormat::RESET . "%s[%s]: %s" . TextFormat::RESET;

	private bool $useFormattingCodes;

	private string $timezone;


	public function __construct(bool $useFormattingCodes, \DateTimeZone $timezone, bool $logDebug = false){
		$this->logDebug = $logDebug;

		$this->useFormattingCodes = $useFormattingCodes;
		$this->timezone = $timezone->getName();
	}

	/**
	 * Возвращает текущий формат логгера, используемый для вывода на консоль.
	 */
	public function getFormat() : string{
		return $this->format;
	}

	/**
	 * Задает формат логгера, который будет использоваться для вывода текста в консоль.
	 * Это должна быть строка с поддержкой sprintf(), принимающая 5 строковых аргументов:
	 * - время
	 * - цвет
	 * - имя потока
	 * - префикс (debug, info и т.д.)
	 * - сообщение
	 *
	 * @see http://php.net/manual/en/function.sprintf.php
	 */
	public function setFormat(string $format) : void{
		$this->format = $format;
	}

	public function emergency($message){
		$this->send($message, \LogLevel::EMERGENCY, "EMERGENCY", TextFormat::RED);
	}

	public function alert($message){
		$this->send($message, \LogLevel::ALERT, "ALERT", TextFormat::RED);
	}

	public function critical($message){
		$this->send($message, \LogLevel::CRITICAL, "CRITICAL", TextFormat::RED);
	}

	public function error($message){
		$this->send($message, \LogLevel::ERROR, "ERROR", TextFormat::DARK_RED);
	}

	public function warning($message){
		$this->send($message, \LogLevel::WARNING, "WARNING", TextFormat::YELLOW);
	}

	public function notice($message){
		$this->send($message, \LogLevel::NOTICE, "NOTICE", TextFormat::AQUA);
	}

	public function info($message){
		$this->send($message, \LogLevel::INFO, "INFO", TextFormat::WHITE);
	}

	public function debug($message, bool $force = false){
		if(!$this->logDebug && !$force){
			return;
		}
		$this->send($message, \LogLevel::DEBUG, "DEBUG", TextFormat::GRAY);
	}

	public function setLogDebug(bool $logDebug) : void{
		$this->logDebug = $logDebug;
	}

	/**
	 * @param mixed[][]|null                          $trace
	 *
	 * @phpstan-param list<array<string, mixed>>|null $trace
	 *
	 * @return void
	 */
	public function logException(\Throwable $e, $trace = null){
		$this->critical(implode("\n", Utils::printableExceptionInfo($e, $trace)));
	}

	public function log($level, $message){
		switch($level){
			case LogLevel::EMERGENCY:
				$this->emergency($message);
				break;
			case LogLevel::ALERT:
				$this->alert($message);
				break;
			case LogLevel::CRITICAL:
				$this->critical($message);
				break;
			case LogLevel::ERROR:
				$this->error($message);
				break;
			case LogLevel::WARNING:
				$this->warning($message);
				break;
			case LogLevel::NOTICE:
				$this->notice($message);
				break;
			case LogLevel::INFO:
				$this->info($message);
				break;
			case LogLevel::DEBUG:
				$this->debug($message);
				break;
		}
	}

	/**
	 * @param string $message
	 * @param string $level
	 * @param string $prefix
	 * @param string $color
	 */
	protected function send($message, $level, $prefix, $color) : void{
		$time = new \DateTime('now', new \DateTimeZone($this->timezone));

		$message = sprintf($this->format, $time->format("H:i:s.v"), $color, $prefix, TextFormat::clean($message, false));

		if(!Terminal::isInit()){
			Terminal::init($this->useFormattingCodes); //цветовые коды lazy-init, потому что мы не знаем, были ли они зарегистрированы в этом потоке
		}
		Terminal::writeLine($message);

	}
}
