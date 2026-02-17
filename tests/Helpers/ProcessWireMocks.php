<?php
/**
 * Minimal ProcessWire stubs for unit testing.
 */

namespace {
    $GLOBALS['_pw_wire_registry'] = [];

    if (!function_exists('wire')) {
        function wire(?string $name = null) {
            if ($name === null) {
                return new class {
                    public function error(string $msg): void {}
                    public function warning(string $msg): void {}
                };
            }
            return $GLOBALS['_pw_wire_registry'][$name] ?? null;
        }
    }
}

namespace ProcessWire {

    if (!function_exists('ProcessWire\wire')) {
        function wire(?string $name = null) {
            return \wire($name);
        }
    }

    if (!function_exists('ProcessWire\__')) {
        function __(string $text, string $filename = ''): string {
            return $text;
        }
    }

    if (!class_exists('ProcessWire\Wire')) {
        class Wire {
            public function error(string $msg): void {}
            public function warning(string $msg): void {}
        }
    }

    if (!interface_exists('ProcessWire\Module')) {
        interface Module {}
    }

    if (!class_exists('ProcessWire\Process')) {
        class Process extends Wire {}
    }

    if (!class_exists('ProcessWire\ModuleConfig')) {
        class ModuleConfig {
            public function _($text) { return $text; }
        }
    }

    if (!class_exists('ProcessWire\Inputfield')) {
        class Inputfield {
            const collapsedYes = 1;
        }
    }

    if (!class_exists('ProcessWire\Page')) {
        class Page {
            public $id;
            public $title;
            public $name;
            public $url;
            public $template;
            public $parent;
            public $created;
            public $modified;
            public $status;
            private array $_data = [];

            public function get($name) {
                return $this->_data[$name] ?? $this->$name ?? null;
            }

            public function set($name, $value) {
                $this->_data[$name] = $value;
                return $this;
            }

            public function getForPage() { return $this; }
            public function of($val = null) {}
            public function setAndSave($field, $value, $options = []) {}
            public function __get($name) { return $this->_data[$name] ?? null; }
            public function __set($name, $value) { $this->_data[$name] = $value; }
            public function __isset($name) { return isset($this->_data[$name]); }
        }
    }

    if (!class_exists('ProcessWire\Template')) {
        class Template {
            public $id;
            public $name;
            public $label;
            public $fields;

            public function __construct() {
                $this->fields = new MockFieldsArray();
            }
        }
    }

    if (!class_exists('ProcessWire\Field')) {
        class Field {
            const flagSystem = 8;
            public $id;
            public $name;
            public $label;
            public $type;
            public $flags = 0;
        }
    }

    if (!class_exists('ProcessWire\Fieldtype')) {
        class Fieldtype {
            public function __toString() {
                return (new \ReflectionClass($this))->getShortName();
            }
        }
    }

    if (!class_exists('ProcessWire\FieldtypePageTitle')) { class FieldtypePageTitle extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypePageTitleLanguage')) { class FieldtypePageTitleLanguage extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeText')) { class FieldtypeText extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeTextarea')) { class FieldtypeTextarea extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeTextLanguage')) { class FieldtypeTextLanguage extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeTextareaLanguage')) { class FieldtypeTextareaLanguage extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeImage')) { class FieldtypeImage extends Fieldtype {} }
    if (!class_exists('ProcessWire\FieldtypeFile')) { class FieldtypeFile extends Fieldtype {} }

    if (!class_exists('ProcessWire\MockFieldsArray')) {
        class MockFieldsArray implements \IteratorAggregate, \Countable {
            private array $items = [];

            public function add($field): self {
                $this->items[] = $field;
                return $this;
            }

            public function has($field): bool {
                foreach ($this->items as $item) {
                    if ($item === $field || (isset($item->id) && isset($field->id) && $item->id === $field->id)) {
                        return true;
                    }
                }
                return false;
            }

            public function count(): int { return count($this->items); }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }

            public function get($name) {
                foreach ($this->items as $item) {
                    if (is_string($name) && isset($item->name) && $item->name === $name) return $item;
                    if (is_int($name) && isset($item->id) && $item->id === $name) return $item;
                }
                return null;
            }
        }
    }

    if (!class_exists('ProcessWire\MockCollection')) {
        class MockCollection implements \IteratorAggregate, \Countable {
            private array $items = [];

            public function __construct(array $items = []) { $this->items = $items; }
            public function add($item): self { $this->items[] = $item; return $this; }

            public function get($nameOrId) {
                foreach ($this->items as $item) {
                    if (is_object($item)) {
                        if (is_int($nameOrId) && isset($item->id) && $item->id === $nameOrId) return $item;
                        if (is_string($nameOrId) && isset($item->name) && $item->name === $nameOrId) return $item;
                    }
                }
                return null;
            }

            public function find(string $selector) { return $this; }
            public function count(?string $selector = null): int { return count($this->items); }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        }
    }

    if (!class_exists('ProcessWire\MockModulesService')) {
        class MockModulesService {
            private array $modules = [];

            public function register(string $name, $module): void {
                $this->modules[$name] = $module;
            }

            public function get(string $name) {
                return $this->modules[$name] ?? null;
            }

            public function getConfig(string $name): array {
                return [];
            }

            public function saveConfig(string $name, array $config): bool {
                return true;
            }
        }
    }

    if (!class_exists('ProcessWire\MockPromptAIModule')) {
        class MockPromptAIModule {
            public array $warnings = [];

            public function warning(string $msg): void {
                $this->warnings[] = $msg;
            }

            public function fieldValueToString($value, $fieldtype): string {
                return (string) $value;
            }

            public function get($name) {
                return null;
            }
        }
    }

    if (!class_exists('ProcessWire\HookEvent')) {
        class HookEvent {
            public $object;
            public $return;
            public function arguments($name) { return null; }
        }
    }

    if (!class_exists('ProcessWire\Pageimage')) {
        class Pageimage {
            public $basename;
            public $description;
            public $filename;
            public function width($w) { return $this; }
        }
    }
}
