<?php

namespace BeeAZ\AZMinion\entity;

use pocketmine\entity\Human;
use pocketmine\nbt\tag\{CompoundTag, ListTag};
use pocketmine\player\Player;
use pocketmine\event\entity\{EntityDamageEvent, EntityDamageByEntityEvent};
use pocketmine\item\{Item, VanillaItems};
use pocketmine\inventory\Inventory;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\world\sound\{AnvilUseSound, PopSound, XpLevelUpSound, XpCollectSound};
use pocketmine\world\particle\HappyVillagerParticle;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use dktapps\pmforms\{MenuForm, MenuOption, ModalForm};
use BeeAZ\AZFishingRod\Main as AZFishingMain;
use pocketmine\Server;
use pocketmine\scheduler\ClosureTask;

abstract class BaseMinion extends Human
{

    protected array $items = [];
    protected string $owner = "";
    protected int $tier = 1;
    protected int $storageLevel = 1;
    protected bool $hasAutoSell = false;
    protected bool $autoSellEnabled = false;

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        $this->owner = $nbt->getString("Owner", "");
        $this->tier = $nbt->getInt("Tier", 1);
        $this->storageLevel = $nbt->getInt("StorageLevel", 1);
        $this->hasAutoSell = $nbt->getByte("HasAutoSell", 0) === 1;
        $this->autoSellEnabled = $nbt->getByte("AutoSellEnabled", 0) === 1;

        if ($nbt->getTag("MinionItems")) {
            foreach ($nbt->getListTag("MinionItems") as $t) {
                $this->items[] = Item::nbtDeserialize($t);
            }
        }

        $this->setCanSaveWithChunk(true);
        $this->setNameTagAlwaysVisible(true);
        $this->setScale(0.6);
        $this->updateNameTag();
        $this->getInventory()->setItemInHand($this->getToolItem());
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        $nbt->setString("Owner", $this->owner);
        $nbt->setInt("Tier", $this->tier);
        $nbt->setInt("StorageLevel", $this->storageLevel);
        $nbt->setByte("HasAutoSell", $this->hasAutoSell ? 1 : 0);
        $nbt->setByte("AutoSellEnabled", $this->autoSellEnabled ? 1 : 0);

        $list = new ListTag();
        foreach ($this->items as $i) $list->push($i->nbtSerialize());
        $nbt->setTag("MinionItems", $list);
        return $nbt;
    }

    protected function getMaxSlots(): int
    {
        return $this->storageLevel * 54;
    }

    protected function isInventoryFull(): bool
    {
        return count($this->items) >= $this->getMaxSlots();
    }

    protected function addItem(Item $item): void
    {
        if ($this->isInventoryFull()) {
            foreach ($this->items as $k => $i) {
                if ($i->equals($item) && $i->getCount() < $i->getMaxStackSize()) {
                    $this->items[$k]->setCount($this->items[$k]->getCount() + $item->getCount());
                    return;
                }
            }
            return;
        }

        foreach ($this->items as $k => $i) {
            if ($i->equals($item)) {
                $this->items[$k]->setCount($this->items[$k]->getCount() + $item->getCount());
                return;
            }
        }
        $this->items[] = $item;
    }

    public function sellInventory(): float
    {
        $totalMoney = 0.0;
        $newItems = [];

        foreach ($this->items as $item) {
            $price = 0.0;
            $name = $item->getName();

            if ($item->getNamedTag()->getTag("fish_price")) {
                $price = $item->getNamedTag()->getFloat("fish_price");
            } else {
                if (strpos($name, "Log") !== false || strpos($name, "Wood") !== false) {
                    $price = 10;
                } elseif (strpos($name, "Stone") !== false || strpos($name, "Cobblestone") !== false) {
                    $price = 2;
                } elseif (strpos($name, "Coal") !== false) {
                    $price = 5;
                } elseif (strpos($name, "Iron") !== false) {
                    $price = 15;
                } elseif (strpos($name, "Gold") !== false) {
                    $price = 30;
                } elseif (strpos($name, "Diamond") !== false) {
                    $price = 100;
                } elseif (strpos($name, "Emerald") !== false) {
                    $price = 150;
                }
            }

            if ($price > 0) {
                $totalMoney += $price * $item->getCount();
            } else {
                $newItems[] = $item;
            }
        }

        $this->items = $newItems;

        if ($totalMoney > 0) {
            $player = Server::getInstance()->getPlayerExact($this->owner);
            if ($player instanceof Player) {
                AZFishingMain::getInstance()->addMoney($player, $totalMoney);
                $player->sendMessage("§l§a⚡ §r§fMinion đã bán vật phẩm thu về §e$" . number_format($totalMoney));
                $this->getWorld()->addSound($this->getPosition(), new XpCollectSound());
            }
        }

        return $totalMoney;
    }

    public function entityBaseTick(int $diff = 1): bool
    {
        $u = parent::entityBaseTick($diff);

        if ($this->hasAutoSell && $this->autoSellEnabled && $this->isInventoryFull()) {
            $this->sellInventory();
        }

        if ($this->getTypeName() !== "FISHER") {
            $delay = 160 - ($this->tier * 20);
            if ($delay < 60) $delay = 60;

            if ($this->ticksLived % $delay === 0) {
                if (!$this->isInventoryFull()) {
                    $this->doWork();
                } else {
                    $this->updateNameTag();
                }
            }
        } else {
            $this->doWork();
        }
        return $u;
    }

    public function attack(EntityDamageEvent $s): void
    {
        $s->cancel();
        if ($s instanceof EntityDamageByEntityEvent) {
            $p = $s->getDamager();
            if ($p instanceof Player) {
                if ($p->getName() === $this->owner || $p->hasPermission("azminion.admin")) {
                    $this->openMainMenu($p);
                } else {
                    $p->sendMessage("§cMinion này không phải của bạn!");
                }
            }
        }
    }

    public function openMainMenu(Player $p): void
    {
        $autoSellOwnership = $this->hasAutoSell ? "§aĐã sở hữu" : "§cChưa mua";
        $autoSellSwitch = "§c(Chưa mua)";
        if ($this->hasAutoSell) {
            $autoSellSwitch = $this->autoSellEnabled ? "§aĐang Bật" : "§cĐang Tắt";
        }

        $p->sendForm(new MenuForm(
            "§l§0⚡ QUẢN LÝ MINION ⚡",
            "§fLoại: §b" . $this->getTypeName() . "\n§fCấp Minion: §e" . $this->tier . "\n§fCấp Kho: §e" . $this->storageLevel . " §7(" . count($this->items) . "/" . $this->getMaxSlots() . ")\n§fAuto Sell: $autoSellOwnership",
            [
                new MenuOption("§l§0⚡ MỞ KHO ĐỒ ⚡\n§r§8Xem vật phẩm"),
                new MenuOption("§l§0⚡ NÂNG CẤP MINION ⚡\n§r§8Tăng tốc độ"),
                new MenuOption("§l§0⚡ NÂNG CẤP KHO ⚡\n§r§8Mở rộng sức chứa"),
                new MenuOption("§l§0⚡ BẬT\TẮT AUTO SELL ⚡\n§r§8Trạng thái: $autoSellSwitch"),
                new MenuOption("§l§0⚡ BÁN TOÀN BỘ ⚡\n§r§8Bán thủ công ngay"),
                new MenuOption("§l§0⚡ THU HỒI ⚡\n§r§8Cất vào túi"),
                new MenuOption("§l§4❌ ĐÓNG")
            ],
            function (Player $p, int $sel): void {
                if ($sel === 0) $this->openInv($p);
                if ($sel === 1) $this->openUpgradeMinion($p);
                if ($sel === 2) $this->openUpgradeStorage($p);
                if ($sel === 3) {
                    if (!$this->hasAutoSell) {
                        $this->openUpgradeAutoSell($p);
                    } else {
                        $this->autoSellEnabled = !$this->autoSellEnabled;
                        $p->sendMessage("§l§e⚡ THÔNG BÁO! §r§fĐã " . ($this->autoSellEnabled ? "§aBẬT" : "§cBẬT") . " tính năng tự động bán.");
                        $this->openMainMenu($p);
                    }
                }
                if ($sel === 4) {
                    $this->sellInventory();
                    $this->openMainMenu($p);
                }
                if ($sel === 5) $this->pickupMinion($p);
            }
        ));
    }

    public function openUpgradeAutoSell(Player $p): void
    {
        $cost = 100000;

        $p->sendForm(new ModalForm(
            "§l§0⚡ MUA AUTO SELL ⚡",
            "§fTính năng: §eTự động bán vật phẩm khi đầy kho sau khi mua xong có thể bật tắt tùy ý.\n" .
                "§fChi phí: §6" . number_format($cost) . " Gold\n\n" .
                "§7Bạn có chắc chắn muốn mua vĩnh viễn không?",
            function (Player $player, bool $choice) use ($cost): void {
                if ($choice) {
                    if (AZFishingMain::getInstance()->reduceGold($player, $cost)) {
                        $this->hasAutoSell = true;
                        $this->autoSellEnabled = true;
                        $this->getWorld()->addSound($this->getPosition(), new XpLevelUpSound(30));
                        $player->sendMessage("§l§a⚡ THÀNH CÔNG! §r§fĐã mua vĩnh viễn Auto Sell. Bạn có thể bật tắt trong Menu.");
                        $this->openMainMenu($player);
                    } else {
                        $player->sendMessage("§cBạn không đủ Gold ($cost)!");
                    }
                } else {
                    $this->openMainMenu($player);
                }
            },
            "§l§aXÁC NHẬN MUA",
            "§l§cHỦY"
        ));
    }

    public function openInv(Player $p): void
    {
        if ($this->storageLevel === 1) {
            $this->openStoragePage($p, 1);
            return;
        }
        $buttons = [];
        for ($i = 1; $i <= $this->storageLevel; $i++) {
            $start = ($i - 1) * 54;
            $count = 0;
            for ($j = 0; $j < 54; $j++) {
                if (isset($this->items[$start + $j])) $count++;
            }
            $buttons[] = new MenuOption("§l§0⚡ KHO SỐ $i ⚡\n§r§8($count/54 ô)");
        }
        $p->sendForm(new MenuForm("§l§0CHỌN KHO", "§fCấp độ kho: §e" . $this->storageLevel, $buttons, function (Player $p, int $sel): void {
            $this->openStoragePage($p, $sel + 1);
        }, function (Player $p): void {
            $this->openMainMenu($p);
        }));
    }

    public function openStoragePage(Player $p, int $page): void
    {
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName("Kho số $page (Cấp " . $this->storageLevel . ")");
        $startIndex = ($page - 1) * 54;
        $pageItems = array_slice($this->items, $startIndex, 54);
        $guiContents = [];
        foreach ($pageItems as $k => $item) {
            $guiContents[$k] = $item;
        }
        $menu->getInventory()->setContents($guiContents);
        $menu->setListener(fn(InvMenuTransaction $t) => $t->continue());
        $menu->setInventoryCloseListener(function (Player $p, Inventory $inv) use ($page, $startIndex): void {
            $contents = $inv->getContents();
            $tempItems = [];
            foreach ($this->items as $idx => $item) {
                if ($idx < $startIndex || $idx >= $startIndex + 54) {
                    $tempItems[] = $item;
                }
            }
            foreach ($contents as $item) $tempItems[] = $item;
            $this->items = array_values($tempItems);
            $this->updateNameTag();
            if ($this->storageLevel > 1) {
                AZFishingMain::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($p) {
                    if ($p->isOnline()) $this->openInv($p);
                }), 5);
            }
        });
        $menu->send($p);
    }

    public function openUpgradeMinion(Player $p): void
    {
        if ($this->tier >= 5) {
            $p->sendMessage("§aCấp độ tối đa!");
            return;
        }
        $cost = match ($this->tier) {
            1 => 5000,
            2 => 15000,
            3 => 30000,
            4 => 50000,
            default => 999999
        };
        $curr = ($this->tier === 1) ? "Xu" : "Gold";
        $p->sendForm(new ModalForm("§l§0⚡ NÂNG CẤP MINION ⚡", "§fCấp: §e" . $this->tier . " -> " . ($this->tier + 1) . "\n§fGiá: §6" . number_format($cost) . " $curr", function (Player $player, bool $choice) use ($cost, $curr): void {
            if ($choice) {
                $ok = ($curr === "Xu") ? AZFishingMain::getInstance()->reduceMoney($player, $cost) : AZFishingMain::getInstance()->reduceGold($player, $cost);
                if ($ok) {
                    $this->tier++;
                    $this->updateNameTag();
                    $this->getWorld()->addSound($this->getPosition(), new XpLevelUpSound(30));
                    $player->sendMessage("§aThành công!");
                } else $player->sendMessage("§cKhông đủ $curr!");
            } else $this->openMainMenu($player);
        }, "§aĐỒNG Ý", "§cHỦY"));
    }

    public function openUpgradeStorage(Player $p): void
    {
        if ($this->storageLevel >= 6) {
            $p->sendMessage("§aKho tối đa!");
            return;
        }
        $cost = $this->storageLevel * 10000;
        $p->sendForm(new ModalForm("§l§6⚡ NÂNG CẤP KHO ⚡", "§fNâng cấp kho lên §a" . ($this->storageLevel + 1) . "\n§fGiá: §6" . number_format($cost) . " Gold", function (Player $player, bool $choice) use ($cost): void {
            if ($choice) {
                if (AZFishingMain::getInstance()->reduceGold($player, $cost)) {
                    $this->storageLevel++;
                    $this->getWorld()->addSound($this->getPosition(), new AnvilUseSound());
                    $player->sendMessage("§aThành công!");
                } else $player->sendMessage("§cKhông đủ Gold!");
            } else $this->openMainMenu($player);
        }, "§aNÂNG CẤP", "§cHỦY"));
    }

    public function pickupMinion(Player $p): void
    {
        $this->flagForDespawn();
        $i = VanillaItems::NETHER_STAR();
        $i->setCustomName("§r§l§eMINION: " . $this->getTypeName() . " (Cấp $this->tier)");
        $i->getNamedTag()->setString("minion_type", strtolower($this->getTypeName()));
        $i->getNamedTag()->setInt("minion_tier", $this->tier);
        $i->getNamedTag()->setInt("minion_storage", $this->storageLevel);
        $i->getNamedTag()->setByte("minion_has_autosell", $this->hasAutoSell ? 1 : 0);
        $i->getNamedTag()->setByte("minion_autosell_enabled", $this->autoSellEnabled ? 1 : 0);

        if ($p->getInventory()->canAddItem($i)) $p->getInventory()->addItem($i);
        else $p->getWorld()->dropItem($this->getPosition(), $i);

        foreach ($this->items as $it) $p->getWorld()->dropItem($this->getPosition(), $it);
        $this->getWorld()->addSound($this->getPosition(), new PopSound());
        $p->sendMessage("§eĐã thu hồi!");
    }

    protected function updateNameTag(): void
    {
        if ($this->isInventoryFull()) {
            $this->setNameTag("§l§cKHO ĐẦY\n§r§fChủ: " . $this->owner);
        } else {
            $this->setNameTag("§l§e" . $this->getTypeName() . "\n§r§fCấp: $this->tier | Kho: $this->storageLevel | Chủ: " . $this->owner);
        }
    }

    protected function swing(): void
    {
        $this->broadcastAnimation(new ArmSwingAnimation($this));
    }

    abstract protected function doWork(): void;
    abstract protected function getTypeName(): string;
    abstract protected function getToolItem(): Item;
}
