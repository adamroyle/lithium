<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2009, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\util\reflection;

use \Exception;
use \ReflectionClass;
use \lithium\core\Libraries;
use \lithium\util\Collection;

class Inspector extends \lithium\core\StaticObject {

	protected static $_classes = array(
		'collection' => '\lithium\util\Collection'
	);

	/**
	 * Maps reflect method names to result array keys.
	 *
	 * @var array
	 */
	protected static $_methodMap = array(
		'name'      => 'getName',
		'start'     => 'getStartLine',
		'end'       => 'getEndLine',
		'file'      => 'getFileName',
		'comment'   => 'getDocComment',
		'namespace' => 'getNamespaceName',
		'shortName' => 'getShortName'
	);

	public static function type($identifier) {
		$identifier = ltrim($identifier, '\\');

		if (strpos($identifier, '::')) {
			return (strpos($identifier, '$') !== false) ? 'property' : 'method';
		}
		if (class_exists($identifier) && in_array($identifier, get_declared_classes())) {
			return 'class';
		}
		return 'namespace';
	}

	public static function info($identifier, $info = array()) {
		$info = $info ?: array_keys(static::$_methodMap);
		$type = static::type($identifier);
		$result = array();
		$class = null;

		if ($type == 'method' || $type == 'property') {
			list($class, $identifier) = explode('::', $identifier);
			$classInspector = new ReflectionClass($class);

			$modifiers = array('public', 'private', 'protected', 'abstract', 'final', 'static');
			$result['modifiers'] = array();

			if ($type == 'property') {
				$identifier = substr($identifier, 1);
				$accessor = 'getProperty';
			} else {
				$identifier = str_replace('()', '', $identifier);
				$accessor = 'getMethod';
			}

			try {
				$inspector = $classInspector->{$accessor}($identifier);
			} catch (Exception $e) {
				return null;
			}

			foreach ($modifiers as $mod) {
				$m = 'is' . ucfirst($mod);
				if (method_exists($inspector, $m) && $inspector->{$m}()) {
					$result['modifiers'][] = $mod;
				}
			}
		} elseif ($type == 'class') {
			$inspector = new ReflectionClass($identifier);
		} else {
			return null;
		}

		foreach ($info as $key) {
			if (!isset(static::$_methodMap[$key])) {
				continue;
			}
			if (method_exists($inspector, static::$_methodMap[$key])) {
				$setAccess = (
					($type == 'method' || $type == 'property') &&
					array_intersect($result['modifiers'], array('private', 'protected')) != array()
					 && method_exists($inspector, 'setAccessible')
				);

				if ($setAccess) {
					$inspector->setAccessible(true);
				}
				$result[$key] = $inspector->{static::$_methodMap[$key]}();

				if ($setAccess) {
					$inspector->setAccessible(false);
					$setAccess = false;
				}
			}
		}

		if (isset($result['start']) && isset($result['end'])) {
			$result['length'] = $result['end'] - $result['start'];
		}
		if (isset($result['comment'])) {
			$result += Docblock::comment($result['comment']);
		}
		return $result;
	}

	/**
	 * Gets the executable lines of a class, by examining the start and end lines of each method.
	 *
	 * @param mixed $class Class name as a string or object instance.
	 * @param array $options Set of options:
	 *        -'self': If true (default), only returns lines of methods defined in `$class`,
	 *         excluding methods from inherited classes.
	 *        -'methods': An arbitrary list of methods to search, as a string (single method name)
	 *         or array of method names.
	 *        -'filter': If true, filters out lines containing only whitespace or braces. Note: for
	 *         some reason, the Zend engine does not report `switch` and `try` statements as
	 *         executable lines, as well as parts of multi-line assignment statements, so they are
	 *         filtered out as well.
	 * @return array Returns an array of the executable line numbers of the class.
	 */
	public static function executable($class, $options = array()) {
		$defaults = array(
			'self' => true, 'filter' => true, 'methods' => array(),
			'empty' => array(' ', "\t", '}', ')', ';'), 'pattern' => null,
			'blockOpeners' => array('switch (', 'try {', '} else {', 'do {', '} while')
		);
		$options += $defaults;

		if (empty($options['pattern']) && $options['filter']) {
			$pattern = str_replace(' ', '\s*', join('|', array_map(
				function($str) { return preg_quote($str, '/'); },
				$options['blockOpeners']
			)));
			$options['pattern'] = "/^(({$pattern})|\\$(.+)\($)/";
		}

		if (!$class instanceof ReflectionClass) {
			$class = new ReflectionClass(is_object($class) ? get_class($class) : $class);
		}
		$result = array_filter(static::methods($class, 'ranges', $options + array(
			'group' => false
		)));

		if ($options['filter'] && $class->getFileName()) {
			$file = explode("\n", "\n" . file_get_contents($class->getFileName()));
			$lines = array_intersect_key($file, array_flip($result));
			$result = array_keys(array_filter($lines, function($line) use ($options) {
				$line = trim($line);
				$empty = (strpos($line, '//') === 0 || preg_match($options['pattern'], $line));
				return $empty ? false : (str_replace($options['empty'], '', $line) != '');
			}));
		}
		return $result;
	}

	/**
	 * Returns various information on the methods of an object, in different formats.
	 *
	 * @param mixed $class A string class name or an object instance, from which to get methods.
	 * @param string $format The type and format of data to return. Available options are:
	 *               -null: Returns a `Collection` object containing a `ReflectionMethod` instance
	 *                for each method.
	 *               -'extents': Returns a two-dimensional array with method names as keys, and
	 *                an array with starting and ending line numbers as values.
	 *               -'ranges': Returns a two-dimensional array where each key is a method name,
	 *                and each value is an array of line numbers which are contained in the method.
	 * @param array $options 
	 */
	public static function methods($class, $format = null, $options = array()) {
		$defaults = array('methods' => array(), 'group' => true, 'self' => true);
		$options += $defaults;

		if (!(is_object($class) && $class instanceof ReflectionClass)) {
			$class = new ReflectionClass($class);
		}
		$methods = static::_methods($class, $options);
		$result = array();

		switch ($format) {
			case null:
				return $methods;
			case 'extents':
				if ($methods->getName() == array()) {
					return array();
				}

				$extents = function($start, $end) { return array($start, $end); };
				$result = array_combine($methods->getName(), array_map(
					$extents, $methods->getStartLine(), $methods->getEndLine()
				));
			break;
			case 'ranges':
				$ranges = function($lines) {
					list($start, $end) = $lines;
					return ($end <= $start + 1) ? array() : range($start + 1, $end - 1);
				};
				$result = array_map($ranges, static::methods(
					$class, 'extents', array('group' => true) + $options
				));
			break;
		}

		if ($options['group']) {
			return $result;
		}
		$tmp = $result;
		$result = array();

		array_map(function($ln) use (&$result) { $result = array_merge($result, $ln); }, $tmp);
		return $result;
	}

	/**
	 * Returns an array of lines from a file, class, or arbitrary string, where $data is the data
	 * to read the lines from and $lines is an array of line numbers specifying which lines should
	 * be read.
	 *
	 * @param string $data If `$data` contains newlines, it will be read from directly, and have
	 *               its own lines returned.  If `$data` is a physical file path, that file will be
	 *               read and have its lines returned.  If `$data` is a class name, it will be
	 *               converted into a physical file path and read.
	 * @param array $lines The array of lines to read. If a given line is not present in the data,
	 *              it will be silently ignored.
	 * @return array Returns an array where the keys are matching `$lines`, and the values are the
	 *               corresponding line numbers in `$data`.
	 * @todo Add an $options parameter with a 'context' flag, to pull in n lines of context.
	 */
	public static function lines($data, $lines) {
		if (!strpos($data, "\n")) {
			if (!file_exists($data)) {
				$data = Libraries::path($data);
				if (!file_exists($data)) {
					return null;
				}
			}
			$data = "\n" . file_get_contents($data);
		}
		$c = explode("\n", $data);

		if (!count($c) || !count($lines)) {
			return null;
		}
		return array_intersect_key($c, array_combine($lines, array_fill(0, count($lines), null)));
	}

	/**
	 * Gets the full inheritance list for the given class.
	 *
	 * @param string $class
	 * @param array $options
	 */
	public static function parents($class, $options = array()) {
		$defaults = array('autoLoad' => false);
		$options += $defaults;
		$class = is_object($class) ? get_class($class) : $class;

		if (!class_exists($class, $options['autoLoad'])) {
			return false;
		}
		return class_parents($class);
	}

	/**
	 * Gets an array of classes and their corresponding definition files, or examines a file and
	 * returns the classes it defines.
	 *
	 * @param array $options
	 * @return array
	 */
	public static function classes($options = array()) {
		$defaults = array('group' => 'classes', 'file' => null);
		$options += $defaults;
		
		$list = get_declared_classes();
		$classes = array();

		if (!empty($options['file'])) {
			$loaded = new Collection(array('items' => array_map(
				function($class) { return new ReflectionClass($class); }, $list
			)));

			if (!in_array($options['file'], $loaded->getFileName())) {
				include $options['file'];
				$list = array_diff(get_declared_classes(), $list);
			} else {
				$file = $options['file'];
				$filter = function($class) use ($file) { return $class->getFileName() == $file; };
				$list = $loaded->find($filter)->getName();
			}
		}

		foreach ($list as $class) {
			$inspector = new ReflectionClass($class);

			if ($options['group'] == 'classes') {
				$inspector->getFileName() ? $classes[$class] = $inspector->getFileName() : null;
			} elseif ($options['group'] == 'files') {
				$classes[$inspector->getFileName()][] = $inspector;
			}
		}
		return $classes;
	}

	/**
	 * Gets the static and dynamic dependencies for a class or group of classes.
	 *
	 */
	public static function dependencies($classes, $options = array()) {
		$defaults = array('type' => null);
		$options += $defaults;
		$static = $dynamic = array();
		$trim = function($c) { return trim(trim($c, '\\')); };
		$join = function ($i) { return join('', $i); };

		foreach ((array)$classes as $class) {
			$data = file_get_contents(Libraries::path($class));
			$classes = array_map($join, Parser::find($data, 'use *;', array(
				'return'      => 'content',
				'lineBreaks'  => true,
				'startOfLine' => true,
				'capture'     => array('T_STRING', 'T_NS_SEPARATOR')
			)));

			if ($classes) {
				$static = array_unique(array_merge($static, array_map($trim, $classes)));
			}
			$classes = static::info($class . '::$_classes', array('value'));

			if (isset($classes['value'])) {
				$dynamic = array_merge($dynamic, array_map($trim, $classes['value']));
			}
		}

		if (empty($options['type'])) {
			return array_unique(array_merge($static, $dynamic));
		}
		$type = $options['type'];
		return isset(${$type}) ? ${$type} : null;
	}

	/**
	 * Helper method to get an array of `ReflectionMethod` objects, wrapped in a `Collection`
	 * object, and filtered based on a set of options.
	 *
	 * @param ReflectionClass $class A reflection class instance from which to fetch.
	 * @param array $options The options used to filter the resulting method list.
	 */
	protected static function _methods($class, $options) {
		$defaults = array('methods' => array(), 'self' => true, 'public' => true);
		$options += $defaults;
		$methods = $class->getMethods();

		if (!empty($options['methods'])) {
			$methods = array_filter($methods, function($method) use ($options) {
				return in_array($method->getName(), (array)$options['methods']);
			});
		}

		if ($options['self']) {
			$methods = array_filter($methods, function($method) use ($class) {
				return ($method->getDeclaringClass()->getName() == $class->getName());
			});
		}

		if ($options['public']) {
			$methods = array_filter($methods, function($method) { return $method->isPublic(); });
		}
		return new static::$_classes['collection'](array('items' => $methods));
	}
}

?>