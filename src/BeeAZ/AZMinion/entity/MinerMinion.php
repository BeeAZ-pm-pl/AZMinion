<?php

namespace BeeAZ\AZMinion\entity;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds; // Import ID Block
use pocketmine\block\VanillaBlocks;
use pocketmine\item\{Item, VanillaItems};
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\sound\BlockBreakSound;

class MinerMinion extends BaseMinion
{

    private const ALLOWED_BLOCKS = [
        BlockTypeIds::STONE => true,
        BlockTypeIds::COBBLESTONE => true,
        BlockTypeIds::MOSSY_COBBLESTONE => true,
        BlockTypeIds::OBSIDIAN => true,
        BlockTypeIds::COAL_ORE => true,
        BlockTypeIds::IRON_ORE => true,
        BlockTypeIds::GOLD_ORE => true,
        BlockTypeIds::DIAMOND_ORE => true,
        BlockTypeIds::LAPIS_LAZULI_ORE => true,
        BlockTypeIds::REDSTONE_ORE => true,
        BlockTypeIds::EMERALD_ORE => true,
        BlockTypeIds::NETHER_QUARTZ_ORE => true,
        BlockTypeIds::NETHER_GOLD_ORE => true,
        BlockTypeIds::ANCIENT_DEBRIS => true,

        BlockTypeIds::DEEPSLATE_COAL_ORE => true,
        BlockTypeIds::DEEPSLATE_IRON_ORE => true,
        BlockTypeIds::DEEPSLATE_GOLD_ORE => true,
        BlockTypeIds::DEEPSLATE_DIAMOND_ORE => true,
        BlockTypeIds::DEEPSLATE_LAPIS_LAZULI_ORE => true,
        BlockTypeIds::DEEPSLATE_REDSTONE_ORE => true,
        BlockTypeIds::DEEPSLATE_EMERALD_ORE => true,
        BlockTypeIds::DEEPSLATE => true,
        BlockTypeIds::COBBLED_DEEPSLATE => true
    ];

    protected function getTypeName(): string
    {
        return "MINER";
    }

    protected function getToolItem(): Item
    {
        return VanillaItems::DIAMOND_PICKAXE();
    }

    protected function doWork(): void
    {
        $pos = $this->getPosition()->addVector($this->getDirectionVector());
        $block = $this->getWorld()->getBlock($pos);

        if (isset(self::ALLOWED_BLOCKS[$block->getTypeId()])) {
            $this->swing();
            $this->getWorld()->addParticle($pos, new BlockBreakParticle($block));
            $this->getWorld()->addSound($pos, new BlockBreakSound($block));

            foreach ($block->getDrops(VanillaItems::DIAMOND_PICKAXE()) as $drop) {
                $this->addItem($drop);
            }
            $this->getWorld()->setBlock($pos, VanillaBlocks::AIR());
        }
    }
}
