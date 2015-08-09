<?php
namespace Caper;

class Config
{
    public $cwd;
    public $scripts = [];
    public $filter;
    public $bootstrap = [
        'vendor/autoload.php',
    ];

    function __construct($cwd, \Caper\Filter $filter=null)
    {
        $this->filter = $filter ?: new \Caper\Filter;
        $this->cwd = $cwd;
    }

    static function fromFile($file, $cwd)
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION); 
        if ($extension === 'yaml' || $extension === 'yml') {
            return self::fromYaml(file_get_contents($file), $cwd);
        }
        elseif ($extension === 'json') {
            return self::fromJson(file_get_contents($file), $cwd);
        }
        else {
            throw new \InvalidArgumentException("Unknown file type $extension");
        }
    }

    static function fromJson($json, $cwd)
    {
        $parser = new \Symfony\Component\Yaml\Parser;
        $config = self::fromArray(json_decode($json) ?: []);
        return $config;
    }

    static function fromYaml($yaml, $cwd)
    {
        $parser = new \Symfony\Component\Yaml\Parser;
        $config = self::fromArray($parser->parse($yaml) ?: [], $cwd);
        return $config;
    }

    static function fromArray(array $config, $cwd)
    {
        $c = new static($cwd);

        $errors = [];

        if ($diff = array_diff(array_keys($config), ['scripts', 'scan'])) {
            $errors[] = 'Unknown config keys: '.implode(', ', $diff);
        }

        bootstrap: if (isset($config['bootstrap'])) {
            $bootstrap = is_string($config['bootstrap']) ? [$config['bootstrap']] : $config['bootstrap'];
            $valid = true;
            foreach ($bootstrap as $script) {
                if (!is_string($script)) {
                    $valid = false;
                    break;
                }
            }
            if (!is_array($bootstrap)) {
                $valid = false;
            }
            if (!$valid) {
                $errors[] = "'bootstrap' must be a list of scripts or single script";
            }
        }

        scripts: if (isset($config['scripts'])) {
            foreach ($config['scripts'] as $idx => $script) {
                if (!is_array($script) || !isset($script['type'])) {
                    $errors[] = "Script at index $idx invalid";
                    continue;
                }
                if (!in_array($script['type'], ['php', 'shell'])) {
                    $errors[] = "Unknown script type at index $idx";
                    continue;
                }
                $c->scripts[] = $script;
            }
        }

        filter: if (isset($config['scan'])) {
            foreach ($config['scan'] as $idx=>$item) {
                if (!is_array($item) || !isset($item['type'])) {
                    $errors[] = "Scan at index $idx invalid";
                    continue;
                }
            
                $type = $item['type'];
                if ($item['type'] == 'include' || $item['type'] == 'exclude') {
                    $status = $item['type'] == 'include';

                    if (isset($item['namespace'])) {
                        $ns = explode('\\', trim($item['namespace'], '\\'));
                        $c->filter->add($status, $ns);
                    }
                    elseif (isset($item['class'])) {
                        $c->filter->add($status, ...\Caper\Filter::parseName($item['class']));
                    }
                    elseif (isset($item['method'])) {
                        $c->filter->add($status, ...\Caper\Filter::parseName($item['method']));
                    }
                    elseif (isset($item['all'])) {
                        $c->filter->add($status, null);
                    }
                    else {
                        $errors[] = "Unknown scan type {$type} at index {$idx}";
                        continue;
                    }
                }
            }
        }
        
        if ($errors) {
            throw new \Exception("Configuration failed:\n - ".implode("\n - ", $errors)."\n");
        }

        return $c;
    }
}