<?php

namespace ipad54\BedrockEditionClient\player;

use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class LoginInfo{

	private string $username;
	private string $locale;

	private string $deviceId;
	private string $deviceModel;
	private int $deviceOS;

	private UuidInterface $uuid;
	private Skin $skin;

	public function __construct(string $username, ?string $locale = null, ?string $deviceId = null, ?string $deviceModel = null, ?int $deviceOS = null, ?UuidInterface $uuid = null, ?Skin $skin = null){
		$this->username = $username;
		$this->locale = $locale ?? "ru_RU";

		$this->deviceId = $deviceId ?? bin2hex(random_bytes(16));
		$this->deviceModel = $deviceModel ?? "Shoghi Phone 35 PRO MAX XS Dylan Edition";

		$this->deviceOS = $deviceOS ?? DeviceOS::IOS;

		if($this->deviceOS < DeviceOS::ANDROID || $this->deviceOS > DeviceOS::WINDOWS_PHONE){
			throw new \InvalidArgumentException("DeviceOS must be in range " . DeviceOS::ANDROID . "-" . DeviceOS::WINDOWS_PHONE . ", got " . $this->deviceOS);
		}

		$this->uuid = $uuid ?? Uuid::uuid4();
		$this->skin = $skin ?? new Skin("Standard_Custom", str_repeat(random_bytes(3) . "\xff", 4096));
	}

	public function getUsername() : string{
		return $this->username;
	}

	public function getLocale() : string{
		return $this->locale;
	}

	public function getDeviceId() : string{
		return $this->deviceId;
	}

	public function getDeviceModel() : string{
		return $this->deviceModel;
	}

	public function getDeviceOS() : int{
		return $this->deviceOS;
	}

	public function getUuid() : UuidInterface{
		return $this->uuid;
	}

	public function getSkin() : Skin{
		return $this->skin;
	}
}