<?php

namespace BeeAZ\AZMinion\entity;

use pocketmine\block\Block;
use pocketmine\block\Dirt;
use pocketmine\block\Grass;
use pocketmine\block\Leaves;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Wood;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\sound\BlockBreakSound;

class LumberMinion extends BaseMinion
{

    private ?Vector3 $origin = null;
    private ?Vector3 $target = null;
    private array $path = [];
    private int $pathIndex = 0;

    private ?Vector3 $lastPosition = null;
    private int $stuckTicks = 0;

    private bool $isReturning = false;
    private bool $isIdle = false;

    private array $breakQueue = [];
    private int $breakTimer = 0;
    private ?Vector3 $currentBreakTarget = null;

    private int $jumpAttempts = 0;
    private array $blacklistedTargets = [];

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        $this->setStepHeight(1.25);
        $this->setCanClimb(true);
        $this->origin = new Vector3(
            $nbt->getFloat("OriginX", $this->getPosition()->x),
            $nbt->getFloat("OriginY", $this->getPosition()->y),
            $nbt->getFloat("OriginZ", $this->getPosition()->z)
        );
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        if ($this->origin !== null) {
            $nbt->setFloat("OriginX", $this->origin->x);
            $nbt->setFloat("OriginY", $this->origin->y);
            $nbt->setFloat("OriginZ", $this->origin->z);
        }
        return $nbt;
    }

    protected function getTypeName(): string
    {
        return "LUMBER";
    }

    protected function getToolItem(): Item
    {
        return VanillaItems::DIAMOND_AXE();
    }

    public function jump(): void
    {
        if ($this->isIdle || empty($this->path) || !empty($this->breakQueue)) return;

        parent::jump();
        $this->jumpAttempts++;


        if ($this->jumpAttempts > 5) {
            $this->giveUpAndReturn();
        }
    }

    public function entityBaseTick(int $diff = 1): bool
    {
        $u = parent::entityBaseTick($diff);

        if ($this->currentBreakTarget !== null) {
            $this->lookAt($this->currentBreakTarget->add(0.5, 0.5, 0.5));
            $this->setMotion(new Vector3(0, -0.1, 0));
        }

        if (!empty($this->breakQueue) || $this->currentBreakTarget !== null) {
            $this->processBreakQueue();
            $this->jumpAttempts = 0;
            return $u;
        }

        if (!$this->isInventoryFull()) {
            $this->processMovementAndAction();
        }

        return $u;
    }

    protected function doWork(): void
    {
        if (!empty($this->breakQueue) || $this->currentBreakTarget !== null) return;

        if ($this->isInventoryFull()) return;


        if ($this->target === null) {

            if ($this->origin !== null && $this->getPosition()->distance($this->origin) > 2.0) {
                $this->isReturning = true;
                $this->target = $this->origin;
                $this->calculatePath($this->target);

                if (empty($this->path)) {
                    $this->teleport($this->origin->add(0.5, 0, 0.5));
                    $this->target = null;
                }
            } else {

                $this->isIdle = true;
                $this->setMotion(new Vector3(0, -0.1, 0));
                $this->jumpAttempts = 0;


                $this->scanForTree();
                if ($this->target !== null) {
                    $this->isIdle = false;
                    $this->isReturning = false;
                    $this->calculatePath($this->target);
                }
            }
        }
    }


    private function giveUpAndReturn(): void
    {

        if ($this->isReturning && $this->origin !== null) {
            $this->teleport($this->origin->add(0.5, 0, 0.5));
            $this->target = null;
            $this->path = [];
            $this->isReturning = false;
            return;
        }

        if ($this->target !== null) {
            $hash = $this->target->x . ":" . $this->target->y . ":" . $this->target->z;
            $this->blacklistedTargets[$hash] = time() + 10;
        }

        if ($this->origin !== null) {
            $this->isReturning = true;
            $this->target = $this->origin;
            $this->calculatePath($this->origin);


            if (empty($this->path)) {
                $this->teleport($this->origin->add(0.5, 0, 0.5));
                $this->target = null;
                $this->isReturning = false;
            }
        }


        $this->breakQueue = [];
        $this->currentBreakTarget = null;
        $this->jumpAttempts = 0;
        $this->stuckTicks = 0;
        $this->setMotion(Vector3::zero());
    }

    private function processMovementAndAction(): void
    {
        if ($this->target !== null) {
            $this->isIdle = false;

            $dist = $this->getPosition()->distance($this->target);

            if ($this->isReturning) {
                if ($dist <= 1.0) {
                    $this->isReturning = false;
                    $this->target = null;
                    $this->path = [];

                    $this->teleport($this->origin->add(0.5, 0, 0.5));
                } else {
                    $this->moveAlongPath();
                }
                return;
            }

            $block = $this->getWorld()->getBlock($this->target);
            if (!($block instanceof Wood)) {
                $this->target = null;
                $this->path = [];
                return;
            }

            if ($dist <= 3.5) {
                $this->path = [];
                $this->setMotion(Vector3::zero());
                $this->jumpAttempts = 0;

                $trueRoot = $this->findTrueRoot($this->target);
                $this->scanTreeAndQueue($trueRoot);

                if (!empty($this->breakQueue)) {
                    $this->currentBreakTarget = $this->breakQueue[0];
                }

                $this->target = null;
            } else {
                $this->moveAlongPath();
            }
        }
    }

    private function moveAlongPath(): void
    {
        if (empty($this->path)) {
            $this->stuckTicks = 0;
            if ($this->target !== null) {
                $this->calculatePath($this->target);
                if (empty($this->path)) {
                    $this->giveUpAndReturn();
                }
            }
            return;
        }

        if (!isset($this->path[$this->pathIndex])) {
            $this->pathIndex = 0;
            $this->path = [];
            return;
        }

        $targetNode = $this->path[$this->pathIndex];
        $currentPos = $this->getPosition();

        if ($this->lastPosition !== null) {
            if ($currentPos->distanceSquared($this->lastPosition) < 0.005) {
                $this->stuckTicks++;
            } else {
                $this->stuckTicks = 0;
            }
        }
        $this->lastPosition = $currentPos;

        if ($this->stuckTicks > 10) {
            $direction = $targetNode->subtractVector($currentPos)->normalize();
            $frontBlock = $this->getWorld()->getBlock($currentPos->addVector($direction));

            if ($frontBlock instanceof Leaves) {
                $this->getWorld()->setBlock($frontBlock->getPosition(), VanillaBlocks::AIR());
                $this->getWorld()->addSound($frontBlock->getPosition(), new BlockBreakSound($frontBlock));
                $this->stuckTicks = 0;
                return;
            }

            $diagBlock = $this->getWorld()->getBlock($currentPos->addVector($direction)->add(0, 1, 0));
            if ($diagBlock instanceof Leaves) {
                $this->getWorld()->setBlock($diagBlock->getPosition(), VanillaBlocks::AIR());
                $this->getWorld()->addSound($diagBlock->getPosition(), new BlockBreakSound($diagBlock));
            }

            if ($this->onGround && $this->stuckTicks > 20) $this->jump();

            if ($this->stuckTicks > 60) {
                $this->giveUpAndReturn();
                return;
            }
        }

        $flatTarget = new Vector3($targetNode->x + 0.5, $targetNode->y, $targetNode->z + 0.5);
        $dist2d = pow($currentPos->x - $flatTarget->x, 2) + pow($currentPos->z - $flatTarget->z, 2);

        if ($dist2d < 0.25) {
            $this->pathIndex++;
            return;
        }

        $this->lookAt($flatTarget);
        $direction = $flatTarget->subtractVector($currentPos)->normalize();

        $frontPos = $currentPos->addVector($direction->multiply(0.8));
        $frontBlock = $this->getWorld()->getBlock($frontPos);
        $shouldJump = false;

        if ($targetNode->y > $currentPos->floor()->y) {
            $shouldJump = true;
        } elseif ($frontBlock->isSolid() && !($frontBlock instanceof Leaves)) {
            $obstacleHeight = 0.0;
            $boxes = $frontBlock->getCollisionBoxes();
            foreach ($boxes as $bb) {
                $h = $bb->maxY - $currentPos->y;
                if ($h > $obstacleHeight) $obstacleHeight = $h;
            }
            if (empty($boxes) && $frontBlock->isSolid()) {
                $obstacleHeight = ($frontBlock->getPosition()->y + 1) - $currentPos->y;
            }
            if ($obstacleHeight > 1.25) $shouldJump = true;
        }

        if ($shouldJump && $this->onGround) {
            $headCheck = $this->getWorld()->getBlock($currentPos->add(0, 2.5, 0));
            if ($headCheck instanceof Leaves) {
                $this->getWorld()->setBlock($headCheck->getPosition(), VanillaBlocks::AIR());
            }
            if (!$headCheck->isSolid()) $this->jump();
        }

        $this->move($direction->x * 0.3, 0, $direction->z * 0.3);
    }


    private function findTrueRoot(Vector3 $startNode): Vector3
    {
        $checkPos = $startNode;
        $world = $this->getWorld();
        for ($i = 0; $i < 10; $i++) {
            $below = $checkPos->subtract(0, 1, 0);
            $block = $world->getBlock($below);
            if ($block instanceof Wood) {
                $checkPos = $below;
            } else {
                break;
            }
        }
        return $checkPos;
    }

    private function scanTreeAndQueue(Vector3 $rootPos): void
    {
        $this->breakQueue = [];
        $visited = [];
        $toCheck = [$rootPos];
        $world = $this->getWorld();
        $startHash = $rootPos->x . ":" . $rootPos->y . ":" . $rootPos->z;
        $visited[$startHash] = true;
        $limit = 0;
        while (!empty($toCheck) && $limit < 500) {
            $current = array_shift($toCheck);
            $this->breakQueue[] = $current;
            $limit++;
            for ($x = -1; $x <= 1; $x++) {
                for ($y = 0; $y <= 1; $y++) {
                    for ($z = -1; $z <= 1; $z++) {
                        if ($x === 0 && $y === 0 && $z === 0) continue;
                        $nextPos = $current->add($x, $y, $z);
                        $hash = $nextPos->x . ":" . $nextPos->y . ":" . $nextPos->z;
                        if (isset($visited[$hash])) continue;
                        $block = $world->getBlock($nextPos);
                        if ($block instanceof Wood) {
                            $visited[$hash] = true;
                            $toCheck[] = $nextPos;
                        }
                    }
                }
            }
        }
        $myPos = $this->getPosition();
        usort($this->breakQueue, function (Vector3 $a, Vector3 $b) use ($myPos) {
            if ($a->y !== $b->y) {
                return $a->y <=> $b->y;
            }
            return $a->distanceSquared($myPos) <=> $b->distanceSquared($myPos);
        });
    }

    private function processBreakQueue(): void
    {
        if ($this->breakTimer-- > 0) return;
        $this->breakTimer = 3;
        $pos = array_shift($this->breakQueue);
        if ($pos === null) {
            $this->currentBreakTarget = null;
            return;
        }
        $this->currentBreakTarget = empty($this->breakQueue) ? null : $this->breakQueue[0];
        $world = $this->getWorld();
        $block = $world->getBlock($pos);
        if ($block instanceof Wood) {
            $this->swing();
            $world->setBlock($pos, VanillaBlocks::AIR());
            $world->addParticle($pos->add(0.5, 0.5, 0.5), new BlockBreakParticle($block));
            $world->addSound($pos->add(0.5, 0.5, 0.5), new BlockBreakSound($block));
            foreach ($block->getDrops(VanillaItems::AIR()) as $d) {
                $this->addItem($d);
            }
            $down = $world->getBlock($pos->subtract(0, 1, 0));
            if ($down instanceof Dirt || $down instanceof Grass) {
                $name = $block->getName();
                if (strpos($name, "Log") !== false) {
                    $saplingName = str_replace("Log", "Sapling", $name);
                    $query = str_replace(" ", "_", strtolower($saplingName));
                    $item = StringToItemParser::getInstance()->parse($query);
                    if ($item !== null) {
                        $world->setBlock($pos, $item->getBlock());
                    } else {
                        $world->setBlock($pos, VanillaBlocks::OAK_SAPLING());
                    }
                }
            }
        }
    }

    private function calculatePath(Vector3 $target): void
    {
        $startNode = $this->getPosition()->floor();
        $endNode = $target->floor();
        $openList = [];
        $closedList = [];
        $startKey = $startNode->x . ":" . $startNode->y . ":" . $startNode->z;
        $openList[$startKey] = ['pos' => $startNode, 'g' => 0, 'f' => $startNode->distanceSquared($endNode), 'parent' => null];
        $iterations = 0;
        $maxIterations = 250;
        while (!empty($openList) && $iterations < $maxIterations) {
            $iterations++;
            $currentKey = null;
            $lowestF = INF;
            foreach ($openList as $key => $node) {
                if ($node['f'] < $lowestF) {
                    $lowestF = $node['f'];
                    $currentKey = $key;
                }
            }
            if ($currentKey === null) break;
            $currentNode = $openList[$currentKey];
            unset($openList[$currentKey]);
            $closedList[$currentKey] = $currentNode;
            if ($currentNode['pos']->equals($endNode) || $currentNode['pos']->distance($endNode) <= 2.0) {
                $path = [];
                $curr = $currentNode;
                while ($curr['parent'] !== null) {
                    $path[] = $curr['pos'];
                    $curr = $curr['parent'];
                }
                $this->path = array_reverse($path);
                $this->pathIndex = 0;
                return;
            }
            $neighbors = [
                new Vector3(1, 0, 0),
                new Vector3(-1, 0, 0),
                new Vector3(0, 0, 1),
                new Vector3(0, 0, -1),
                new Vector3(1, 1, 0),
                new Vector3(-1, 1, 0),
                new Vector3(0, 1, 1),
                new Vector3(0, 1, -1),
                new Vector3(1, -1, 0),
                new Vector3(-1, -1, 0),
                new Vector3(0, -1, 1),
                new Vector3(0, -1, -1)
            ];
            foreach ($neighbors as $offset) {
                $neighborPos = $currentNode['pos']->addVector($offset);
                $neighborKey = $neighborPos->x . ":" . $neighborPos->y . ":" . $neighborPos->z;
                if (isset($closedList[$neighborKey])) continue;
                $blockFeet = $this->getWorld()->getBlock($neighborPos);
                $blockHead = $this->getWorld()->getBlock($neighborPos->add(0, 1, 0));
                $feetPassable = !$blockFeet->isSolid() || $blockFeet instanceof Leaves || $blockFeet->getName() === "Snow Layer" || strpos($blockFeet->getName(), "Carpet") !== false;
                $headPassable = !$blockHead->isSolid() || $blockHead instanceof Leaves;
                if (!$feetPassable || !$headPassable) continue;
                $ground = $this->getWorld()->getBlock($neighborPos->subtract(0, 1, 0));
                if (!$ground->isSolid() && !($ground instanceof Leaves)) continue;
                $gScore = $currentNode['g'] + 1;
                if (!isset($openList[$neighborKey]) || $gScore < $openList[$neighborKey]['g']) {
                    $openList[$neighborKey] = ['pos' => $neighborPos, 'g' => $gScore, 'f' => $gScore + $neighborPos->distanceSquared($endNode), 'parent' => $currentNode];
                }
            }
        }
        $this->path = [$target];
        $this->pathIndex = 0;
    }

    private function scanForTree(): void
    {
        if ($this->origin === null) return;
        $maxRadius = 2 + $this->tier;
        $world = $this->getWorld();
        for ($r = 1; $r <= $maxRadius; $r++) {
            for ($x = -$r; $x <= $r; $x++) {
                for ($z = -$r; $z <= $r; $z++) {
                    if (max(abs($x), abs($z)) !== $r) continue;
                    for ($y = -5; $y <= 10; $y++) {
                        $t = $this->origin->add($x, $y, $z);
                        $hash = $t->x . ":" . $t->y . ":" . $t->z;
                        if (isset($this->blacklistedTargets[$hash])) {
                            if ($this->blacklistedTargets[$hash] > time()) {
                                continue;
                            } else {
                                unset($this->blacklistedTargets[$hash]);
                            }
                        }
                        $block = $world->getBlock($t);
                        if ($block instanceof Wood) {
                            $this->target = $t;
                            $this->calculatePath($this->target);
                            return;
                        }
                    }
                }
            }
        }
    }
}
