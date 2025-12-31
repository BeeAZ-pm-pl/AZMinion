<?php

namespace BeeAZ\AZMinion;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{Command, CommandSender};
use pocketmine\player\Player;
use pocketmine\entity\{Entity, EntityFactory, EntityDataHelper, Human};
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
use pocketmine\item\VanillaItems;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\entity\Location;
use muqsit\invmenu\InvMenuHandler;
use BeeAZ\AZMinion\entity\{MinerMinion, LumberMinion, FisherMinion};

class Main extends PluginBase implements Listener
{
    private static Main $instance;

    public function onEnable(): void
    {
        self::$instance = $this;
        if (!InvMenuHandler::isRegistered()) InvMenuHandler::register($this);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $r = function (string $c, string $id) {
            EntityFactory::getInstance()->register($c, function (World $w, CompoundTag $n) use ($c): Entity {
                return new $c(EntityDataHelper::parseLocation($n, $w), Human::parseSkinNbt($n), $n);
            }, [$id]);
        };

        $r(MinerMinion::class, "az:miner_minion");
        $r(LumberMinion::class, "az:lumber_minion");
        $r(FisherMinion::class, "az:fisher_minion");
    }

    public static function getInstance(): Main
    {
        return self::$instance;
    }

    public function onCommand(CommandSender $s, Command $c, string $l, array $a): bool
    {
        if (!$s instanceof Player) return false;
        if (empty($a)) return false;

        $type = strtolower($a[0]);
        $tier = isset($a[1]) ? (int)$a[1] : 1;
        if ($tier < 1) $tier = 1;
        if ($tier > 5) $tier = 5;

        if (!in_array($type, ["miner", "lumber", "fisher"])) return false;

        $i = VanillaItems::NETHER_STAR();
        $i->setCustomName("§r§l§eMINION: " . strtoupper($type) . " (Cấp $tier)");
        $i->getNamedTag()->setString("minion_type", $type);
        $i->getNamedTag()->setInt("minion_tier", $tier);
        $i->getNamedTag()->setInt("minion_storage", 1);
        $i->getNamedTag()->setByte("minion_autosell", 0);

        $s->getInventory()->addItem($i);
        $s->sendMessage("§aMinion $type Tier $tier");
        return true;
    }

    public function onInteract(PlayerInteractEvent $e): void
    {
        if ($e->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
        $i = $e->getItem();

        $tag = $i->getNamedTag();
        if ($tag->getTag("minion_type")) {
            $t = $tag->getString("minion_type");
            $tier = $tag->getInt("minion_tier", 1);
            $storage = $tag->getInt("minion_storage", 1);
            $autosell = $tag->getByte("minion_autosell", 0);

            $player = $e->getPlayer();
            $block = $e->getBlock();
            $world = $block->getPosition()->getWorld();
            $vec = $block->getPosition()->add(0.5, 1, 0.5);
            $yaw = $player->getLocation()->getYaw();

            $location = Location::fromObject($vec, $world, $yaw, 0);

            $nbt = CompoundTag::create()
                ->setString("Owner", $player->getName())
                ->setInt("Tier", $tier)
                ->setInt("StorageLevel", $storage)
                ->setByte("AutoSell", $autosell);

            $skin = $player->getSkin();

            $entity = match ($t) {
                "miner" => new MinerMinion($location, $skin, $nbt),
                "lumber" => new LumberMinion($location, $skin, $nbt),
                "fisher" => new FisherMinion($location, $skin, $nbt),
                default => null
            };

            if ($entity !== null) {
                $entity->spawnToAll();
                $i->pop();
                $player->getInventory()->setItemInHand($i);
                $player->sendMessage("§aĐặt Minion thành công!");
            }
            $e->cancel();
        }
    }
}
