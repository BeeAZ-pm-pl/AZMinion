<?php

namespace BeeAZ\AZMinion\entity;

use pocketmine\item\{Item, VanillaItems};
use pocketmine\world\particle\BubbleParticle;
use pocketmine\world\sound\{ThrowSound, PopSound};
use pocketmine\block\Water;
use pocketmine\block\Air;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use BeeAZ\AZFishingRod\utils\FishManager;
use BeeAZ\AZFishingRod\entity\CustomHook;

class FisherMinion extends BaseMinion
{

    private int $state = 0;
    private int $fishTimer = 0;
    private ?int $hookEntityId = null;
    private ?Vector3 $targetWater = null;

    private int $actionCooldown = 0;

    protected function getTypeName(): string
    {
        return "FISHER";
    }

    protected function getToolItem(): Item
    {
        return VanillaItems::FISHING_ROD();
    }

    protected function doWork(): void
    {

        if ($this->actionCooldown > 0) {
            $this->actionCooldown--;
            return;
        }

        if ($this->isInventoryFull()) {
            $this->updateNameTag();
            if ($this->hookEntityId !== null) {
                $hook = $this->getWorld()->getEntity($this->hookEntityId);
                if ($hook !== null && !$hook->isClosed()) $hook->flagForDespawn();
                $this->hookEntityId = null;
            }
            $this->state = 0;
            return;
        }

        $this->updateNameTag();


        if ($this->state === 0) {
            if ($this->targetWater === null || !$this->isValidFishingSpot($this->targetWater)) {
                $this->scanForWater();
            }

            if ($this->targetWater !== null) {
                $this->startFishing();
            }
        } elseif ($this->state === 1) {
            $this->fishTimer--;


            if ($this->hookEntityId !== null) {
                $hook = $this->getWorld()->getEntity($this->hookEntityId);


                if ($hook === null || $hook->isClosed()) {
                    $this->state = 0;
                    $this->hookEntityId = null;
                    $this->actionCooldown = 20;
                    return;
                }


                if ($hook->onGround) {

                    $this->reelIn(false);

                    $this->actionCooldown = 40;

                    $this->targetWater = null;
                    return;
                }

                if ($this->fishTimer < 40 && $this->fishTimer % 5 == 0) {
                    $this->getWorld()->addParticle($hook->getPosition(), new BubbleParticle());
                }
            }

            if ($this->fishTimer <= 0) {
                $this->reelIn(true);
            }
        }
    }

    private function isValidFishingSpot(Vector3 $pos): bool
    {
        $block = $this->getWorld()->getBlock($pos);
        if (!($block instanceof Water)) return false;

        $up = $this->getWorld()->getBlock($pos->add(0, 1, 0));
        if (!($up instanceof Air) && !($up instanceof Water) && $up->isSolid()) {
            return false;
        }
        return true;
    }

    private function scanForWater(): void
    {
        $radius = 7;
        $eyePos = $this->getEyePos();
        $nearestDist = 999.0;
        $bestBlock = null;

        for ($x = -$radius; $x <= $radius; $x++) {
            for ($z = -$radius; $z <= $radius; $z++) {
                for ($y = -5; $y <= 2; $y++) {
                    $target = $this->getPosition()->add($x, $y, $z);

                    if ($this->isValidFishingSpot($target)) {
                        $midPoint = $eyePos->addVector($target->subtractVector($eyePos)->multiply(0.5));
                        if ($this->getWorld()->getBlock($midPoint)->isSolid()) continue;

                        $dist = $this->getPosition()->distanceSquared($target);
                        if ($dist < $nearestDist) {
                            $nearestDist = $dist;
                            $bestBlock = $target;
                        }
                    }
                }
            }
        }
        $this->targetWater = $bestBlock;
    }

    private function startFishing(): void
    {
        $this->swing();
        $this->getWorld()->addSound($this->getPosition(), new ThrowSound());

        $eyePos = $this->getEyePos();
        $targetPos = $this->targetWater->add(0.5, 0.5, 0.5);

        $diff = $targetPos->subtractVector($eyePos);
        $hDist = sqrt($diff->x ** 2 + $diff->z ** 2);

        $yaw = rad2deg(atan2(-$diff->x, $diff->z));
        $gravityFactor = 0.25;
        $pitchRad = -atan2($diff->y + ($hDist * $gravityFactor), $hDist);
        $pitch = rad2deg($pitchRad);

        $this->setRotation($yaw, $pitch);

        $location = Location::fromObject($eyePos, $this->getWorld(), $yaw, $pitch);
        $hook = new CustomHook($location, $this);

        $direction = $this->getDirectionVector();
        $hook->setMotion($direction->multiply(1.6));

        $hook->getNetworkProperties()->setLong(EntityMetadataProperties::OWNER_EID, $this->getId());
        $hook->spawnToAll();

        $this->hookEntityId = $hook->getId();

        if (class_exists(FishManager::class)) {
            $baseTime = FishManager::getWaitTicks($this->tier) * 2;
            $this->fishTimer = mt_rand((int)($baseTime * 0.9), $baseTime);
        } else {
            $this->fishTimer = 200;
        }

        $this->state = 1;
    }

    private function reelIn(bool $hasCaught = false): void
    {
        $this->swing();
        $this->getWorld()->addSound($this->getPosition(), new PopSound());

        if ($this->hookEntityId !== null) {
            $hook = $this->getWorld()->getEntity($this->hookEntityId);
            if ($hook !== null && !$hook->isClosed()) {
                if ($hasCaught) {
                    $this->getWorld()->addParticle($hook->getPosition(), new BubbleParticle());
                }
                $hook->flagForDespawn();
            }
        }

        if ($hasCaught) {
            if (class_exists(FishManager::class)) {
                $data = FishManager::getRandomFish($this->tier);
                $i = VanillaItems::RAW_SALMON();
                $i->setCustomName("§r§b" . $data['name'] . " §e(" . $data['length'] . " cm)");
                $i->setLore(["§r§fGiá: §a$" . $data['price']]);
                $i->getNamedTag()->setFloat("fish_price", $data['price']);
                $i->getNamedTag()->setString("fish_name", $data['name']);
                $this->addItem($i);
            } else {
                $this->addItem(VanillaItems::RAW_FISH());
            }
        }

        $this->state = 0;
        $this->hookEntityId = null;
    }
}
