<?php

namespace Drupal\wienimal_editor_toolbar\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Menu\InaccessibleMenuLink;
use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\StaticMenuLinkOverrides;
use Drupal\views\Plugin\Derivative\ViewsMenuLink;

class EditorToolbarTreeManipulators
{
    /** @var ConfigFactoryInterface */
    protected $configFactory;

    public function __construct(
		ConfigFactoryInterface $configFactory
    ) {
        $this->configFactory = $configFactory;
    }

    /**
     * Remove certain unneeded menu items for editors
     */
    public function removeMenuItems(array $tree): array
    {
        foreach ($this->getMenuItemsToRemove() as $item) {
            $tree = $this->removeMenuItem($tree, $item);
        }

        return $tree;
    }

    /**
     * Remove a menu item from a menu tree
     */
    public function removeMenuItem(array $tree, string $item): array
    {
        self::walkTreeRecursive(
            $tree,
            function (&$value) use ($item) {
                /** @var MenuLinkTreeElement $value */
                if ($value->link->getPluginId() === $item) {
                    $value->access = AccessResult::forbidden();
                }
            }
        );

        return $tree;
    }

    /**
     * Remove menu item and move subtree items to root
     */
    public function expandMenuItem(array $tree): array
    {
        $items = $this->getMenuItemsToExpand();

        foreach ($items as $item) {
            if (!isset($tree[$item])) {
                continue;
            }

            $contentMenu = $tree[$item]->subtree;

            foreach ($contentMenu as $menuItem => $value) {
                if ($contentMenu[$menuItem]->link instanceof InaccessibleMenuLink) {
                    continue;
                }

                $tree[$menuItem] = $contentMenu[$menuItem];
            }

            unset($tree[$item]);
        }

        return $tree;
    }

    /**
     * Make the 'Add content' menu item not clickable
     */
    public function makeMenuItemsNotClickable(array $tree): array
    {
        $items = $this->getMenuItemsToMakeUnClickable();

        self::walkTreeRecursive(
            $tree,
            function (&$value) use ($items) {
                if (
                    !$value->link instanceof MenuLinkDefault
                    || !in_array($value->link->getPluginId(), $items)
                ) {
                    return;
                }

                $value->link = $this->updateMenuLinkPluginDefinition($value->link, [
                    'route_name' => '<nolink>',
                    'parent' => '',
                ]);
            }
        );

        return $tree;
    }

    /**
     * Remove menu links without link and without children
     */
    public function removeEmptyMenuItems(array $tree): array
    {
        self::walkTreeRecursive(
            $tree,
            function (MenuLinkTreeElement $value, string $key) use ($tree) {
                $children = array_filter(
                    $value->subtree,
                    function (MenuLinkTreeElement $treeElement) {
                        return $treeElement->access->isAllowed();
                    }
                );

                if (
                    in_array($value->link->getRouteName(), ['<nolink>', '<none>'])
                    && empty($children)
                ) {
                    $this->removeMenuItem($tree, $key);
                }
            }
        );

        return $tree;
    }

    /**
     * Check if 'Content overview' and 'Add content' menu items have to be shown
     */
    public function checkCustomMenuItemsAccess(array $tree): array
    {
        if (!$this->getShowContentOverview()) {
            $tree = $this->removeMenuItem($tree, 'wienimal_editor_toolbar.content_overview');
        }

        if ($this->getShowContentOverview() && $this->getConfigValue('content.taxonomy_term') === 'all') {
            $tree = $this->removeMenuItem($tree, 'entity.taxonomy_vocabulary.collection');
        }

        if ($this->getShowContentAdd()) {
            $tree = $this->removeMenuItem($tree, 'admin_toolbar_tools.add_content');
            $tree = $this->removeMenuItem($tree, 'admin_toolbar_tools.extra_links:node.add');
        } else {
            $tree = $this->removeMenuItem($tree, 'wienimal_editor_toolbar.content_add');
        }

        return $tree;
    }

    /**
     * Apply a user function to every item of a menu tree
     *
     * @param MenuLinkTreeElement|MenuLinkTreeElement[] $tree
     * @param callable $callback
     * @return void
     */
    protected static function walkTreeRecursive(array $tree, callable $callback)
    {
        array_walk($tree, [self::class, 'walkTreeRecursiveHandler'], $callback);
    }

    /**
     * @param MenuLinkTreeElement|MenuLinkTreeElement[] $value
     * @param string $key
     * @param callable $callback
     * @return void
     */
    protected static function walkTreeRecursiveHandler($value, string $key, callable $callback)
    {
        if (is_array($value)) {
            array_walk($value, [self::class, 'walkTreeRecursiveHandler'], $callback);
            return;
        }

        if ($value instanceof MenuLinkTreeElement) {
            $callback($value, $key);
            array_walk($value->subtree, [self::class, 'walkTreeRecursiveHandler'], $callback);
        }
    }

    /**
     * Make changes to the plugin definition of a menu link
     * @param MenuLinkDefault|ViewsMenuLink $link
     * @param array $newDefinition
     * @return MenuLinkDefault|ViewsMenuLink|false
     */
    protected function updateMenuLinkPluginDefinition($link, array $newDefinition)
    {
        if ($link instanceof ViewsMenuLink) {
            $link->updateLink($newDefinition, false);
            return $link;

        }

        if ($link instanceof MenuLinkInterface) {
            return new MenuLinkDefault(
                [],
                $link->getPluginId(),
                array_merge($link->getPluginDefinition(), $newDefinition),
                new StaticMenuLinkOverrides($this->configFactory)
            );
        }

        return false;
    }

    protected function getShowContentAdd(): bool
    {
        return $this->getConfigValue('show_combined_add_content');
    }

    protected function getShowContentOverview(): bool
    {
        return $this->getConfigValue('show_combined_content_overview');
    }

    protected function getMenuItemsToExpand(): array
    {
        return $this->getConfigValue('menu_items.expand');
    }

    protected function getMenuItemsToRemove(): array
    {
        return $this->getConfigValue('menu_items.remove');
    }

    protected function getMenuItemsToMakeUnClickable(): array
    {
        return $this->getConfigValue('menu_items.unclickable');
    }

    protected function getConfigValue(string $key)
    {
        return $this->configFactory->get('wienimal_editor_toolbar.settings')->get($key);
    }
}
