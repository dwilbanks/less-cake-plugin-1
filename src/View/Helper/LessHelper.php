<?php
/**
 * Helper for using less.php with cakephp
 *
 * @author Òscar Casajuana <elboletaire@underave.net>
 * @license Apache-2.0
 * @copyright Òscar Casajuana 2013-2015
 */
namespace Less\View\Helper;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Log\Log;
use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\View;

class LessHelper extends Helper
{
    /**
     * {@inheritdoc}
     *
     * @var array
     */
    public $helpers = [
        'Html', 'Url'
    ];

    /**
     * Default lessjs options. Some are defined on setOptions due to the need of using methods.
     *
     * @var array
     */
    public $lessjsDefaults = [
        'env' => 'production'
    ];

    /**
     * Default lessc options. Some are defined on setOptions due to the need of using methods.
     *
     * @var array
     */
    private $parserDefaults = [
        'compress' => true,
        'cache' => true
    ];

    /**
     * Stores the compilation error, in case it occurs
     *
     * @var boolean
     */
    public $error = false;

    /**
     * The css path name, where the output files will be stored
     * (including all the cache generated by less.php, if enabled)
     *
     * @var string
     */
    private $cssPath  = 'css';

    /**
     * {@inheritdoc}
     *
     * Initializes Lessc and cleans less and css paths
     *
     * @param \Cake\View\View $view   The View this helper is being attached to.
     * @param array           $config Configuration settings for the helper.
     */
    public function __construct(View $view, array $config = [])
    {
        parent::__construct($view, $config);

        // Initialize oyejorge/less.php parser
        require_once ROOT . DS . 'vendor' . DS . 'oyejorge' . DS . 'less.php' .
            DS . 'lib' . DS . 'Less' . DS . 'Autoloader.php';

        \Less_Autoloader::register();

        $this->cssPath  = WWW_ROOT . trim($this->cssPath, '/');
    }

    /**
     * Fetches less stylesheets added to css block
     * and compiles them
     *
     * @param  array $options     Options passed to less method.
     * @param  array $modifyVars  ModifyVars passed to less method.
     * @return string             Resulting parsed files.
     */
    public function fetch(array $options = [], array $modifyVars = [])
    {
        if (empty($options['overwrite'])) {
            $options['overwrite'] = true;
        }
        $overwrite = $options['overwrite'];
        unset($options['overwrite']);

        $matches = $css = $less = [];
        preg_match_all('@(<link[^>]+>)@', $this->_View->fetch('css'), $matches);

        if (empty($matches)) {
            return null;
        }

        $matches = array_shift($matches);

        foreach ($matches as $stylesheet) {
            if (strpos($stylesheet, 'rel="stylesheet/less"') !== false) {
                $match = [];
                preg_match('@href="([^"]+)"@', $stylesheet, $match);
                $file = rtrim(array_pop($match), '?');
                array_push($less, $this->less($file, $options, $modifyVars));
                continue;
            }
            array_push($css, $stylesheet);
        }

        if ($overwrite) {
            $this->_View->Blocks->set('css', join($css));
        }

        return join($less);
    }

    /**
     * Compiles any less files passed and returns the compiled css.
     * In case of error, it will load less with the javascritp parser so you'll be
     * able to see any errors on screen. If not, check out the error.log file in your
     * CakePHP's logs folder.
     *
     * @param  mixed $less         The input .less file to be compiled or an array
     *                             of .less files.
     * @param  array  $options     Options in 'js' key will be pased to the less.js
     *                             parser and options in 'parser' will be passed to the less.php parser.
     * @param  array  $modifyVars  Array of modify vars.
     * @return string
     */
    public function less($less = 'styles.less', array $options = [], array $modifyVars = [])
    {
        $options = $this->setOptions($options);
        $less    = (array)$less;

        if ($options['js']['env'] == 'development') {
            return $this->jsBlock($less, $options);
        }

        try {
            $css = $this->compile($less, $options['cache'], $options['parser'], $modifyVars);
            if (isset($options['tag']) && !$options['tag']) {
                return $css;
            }
            if (!$options['cache']) {
                return $this->Html->formatTemplate('style', ['content' => $css]);
            }
            return $this->Html->css($css);
        } catch (\Exception $e) {
            // env must be development in order to see errors on-screen
            if (Configure::read('debug')) {
                $options['js']['env'] = 'development';
            }

            $this->error = $e->getMessage();
            Log::write('error', "Error compiling less file: " . $this->error);

            return $this->jsBlock($less, $options);
        }
    }

    /**
     * Returns the required script and link tags to get less.js working
     *
     * @param  string $less    The input .less file to be loaded.
     * @param  array  $options An array of options to be passed to the `less` configuration var.
     * @return string          The link + script tags need to launch lesscss.
     */
    protected function jsBlock($less, array $options = [])
    {
        $return = '';
        $less   = (array)$less;

        // Append the user less files
        foreach ($less as $les) {
            $return .= $this->Html->meta('link', null, [
                'link' => $les,
                'rel'  => 'stylesheet/less'
            ]);
        }
        // Less.js configuration
        $return .= $this->Html->scriptBlock(sprintf('less = %s;', json_encode($options['js'], JSON_UNESCAPED_SLASHES)));
        // <script> tag for less.js file
        $return .= $this->Html->script($options['less']);

        return $return;
    }

    /**
     * Compiles an input less file to an output css file using the PHP compiler.
     *
     * @param  array   $input       The input .less files to be compiled.
     * @param  array   $options     Options to be passed to the php parser.
     * @param  array   $modifyVars  Less modifyVars.
     * @param  bool    $cache       Whether to cache or not.
     * @return string               If cache is not enabled will return the full
     *                              CSS compiled. Otherwise it will return the
     *                              resulting filename from the compilation.
     */
    protected function compile(array $input, $cache, array $options = [], array $modifyVars = [])
    {
        $parse = $this->prepareInputFilesForParsing($input);

        if ($cache) {
            $options += ['cache_dir' => $this->cssPath];
            return \Less_Cache::Get($parse, $options, $modifyVars);
        }

        $lessc = new \Less_Parser($options);

        foreach ($parse as $file => $path) {
            $lessc->parseFile($file, $path);
        }
        // ModifyVars must be called at the bottom of the parsing,
        // this way we're ensuring they override their default values.
        // http://lesscss.org/usage/#command-line-usage-modify-variable
        $lessc->ModifyVars($modifyVars);

        return $lessc->getCss();
    }

    /**
     * Prepares input files as Less.php parser wants them.
     *
     * @param  array  $input An array with all the input files.
     * @return array
     */
    protected function prepareInputFilesForParsing(array $input = [])
    {
        $parse = [];
        foreach ($input as $in) {
            $less = realpath(WWW_ROOT . $in);
            // If we have plugin notation (Plugin.less/file.less)
            // ensure to properly load the files
            list($plugin, $basefile) = $this->_View->pluginSplit($in, false);
            if (!empty($plugin)) {
                $less = realpath(Plugin::path($plugin) . 'webroot' . DS . $basefile);

                if ($less !== false) {
                    $parse[$less] = $this->assetBaseUrl($plugin, $basefile);
                    continue;
                }
            }
            if ($less !== false) {
                $parse[$less] = '';
                continue;
            }
            // Plugins without plugin notation (/plugin/less/file.less)
            list($plugin, $basefile) = $this->assetSplit($in);
            if ($file = $this->pluginAssetFile([$plugin, $basefile])) {
                $parse[$file] = $this->assetBaseUrl($plugin, $basefile);
                continue;
            }

            // Will probably throw a not found error
            $parse[$in] = '';
        }

        return $parse;
    }

    /**
     * Sets the less configuration var options based on the ones given by the user
     * and our default ones.
     *
     * Here's also where we define the import_callback used by less.php parser,
     * so it can find files successfully even if they're on plugin folders.
     *
     * @param array  $options An array of options containing our options
     *                        combined with the ones for the parsers.
     * @return array $options The resulting $options array.
     */
    protected function setOptions(array $options)
    {
        // @codeCoverageIgnoreStart
        $this->parserDefaults = array_merge($this->parserDefaults, [
            // The import callback ensures that if a file is not found in the
            // app's webroot, it will search for that file in its plugin's
            // webroot path
            'import_callback' => function ($lessTree) {
                if ($pathAndUri = $lessTree->PathAndUri()) {
                    return $pathAndUri;
                }

                $file = $lessTree->getPath();
                list($plugin, $basefile) = $this->assetSplit($file);
                $file = $this->pluginAssetFile([$plugin, $basefile]);

                if ($file) {
                    return [
                        $file,
                        $this->assetBaseUrl($plugin, $basefile)
                    ];
                }

                return null;
            }
        ]);
        // @codeCoverageIgnoreEnd

        if (empty($options['parser'])) {
            $options['parser'] = [];
        }
        if (Configure::read('debug') && !isset($options['parser']['sourceMap'])) {
            $options['parser']['sourceMap'] = true;
        }
        $options['parser'] = array_merge($this->parserDefaults, $options['parser']);

        if (empty($options['js'])) {
            $options['js'] = [];
        }
        $options['js'] = array_merge($this->lessjsDefaults, $options['js']);

        if (empty($options['less'])) {
            $options['less'] = 'Less.less.min';
        }

        if (!isset($options['cache'])) {
            $options['cache'] = true;
        }

        return $options;
    }

    /**
     * Returns tha full base url for the given asset
     *
     * @param  string $plugin Plugin where the asset resides.
     * @param  string $asset  The asset path.
     * @return string
     */
    protected function assetBaseUrl($plugin, $asset)
    {
        $dir  = dirname($asset);
        $path = !empty($dir) && $dir != '.' ? "/$dir" : null;

        return $this->Url->assetUrl($plugin . $path, [
            'fullBase' => true
        ]);
    }

    /**
     * Builds asset file path for a plugin based on url.
     *
     * @param string  $url Asset URL.
     * @return string      Absolute path for asset file.
     */
    protected function pluginAssetFile(array $url)
    {
        list($plugin, $basefile) = $url;

        if ($plugin && Plugin::loaded($plugin)) {
            return realpath(Plugin::path($plugin) . 'webroot' . DS . $basefile);
        }

        return false;
    }

    /**
     * Splits an asset URL
     *
     * @param  string $url Asset URL.
     * @return array       The plugin as first key and the rest basefile as second key.
     */
    protected function assetSplit($url)
    {
        $basefile = ltrim(ltrim($url, '.'), '/');
        $exploded = explode('/', $basefile);
        $plugin   = Inflector::camelize(array_shift($exploded));
        $basefile = implode(DS, $exploded);

        return [
            $plugin, $basefile
        ];
    }
}
