<?php
/**
 * This script is released under the GNU GPL. See the file COPYING.
 * The script is provided as is, with no guarantee either express or implied
 * that it is fit for purpose. Use of the script, or code generated by it, is
 * entirely at the end users risk.
 *
 * TODO: handling of namespaces more nicely
 * TODO: work with full xml schema spec, not just current subset
 *
 * @category Util
 * @package  XSD2PHP
 * @author   David Gillen <david.gillen@nexus451.com>
 * @license  GNU GPL - http://www.gnu.org/licenses/gpl.txt
 * @link     https://github.com/davidgillen/XSD2PHP
 */
if ( $argc != 2 ) {
    die("Usage: php xsd2class <file>\n");
}
$file = file_get_contents($argv[1]);
$file = str_replace('xs:', '', $file); // SimpleXML doesn't like namespaces
$xsd = new SimpleXMLElement($file);

// If we've simpleType outside of main element build up array with their details
$simpleTypeData = array();
if (isset($xsd->simpleType)) {
    extractSimpleTypeData($xsd, $simpleTypeData);
}

processNode($xsd->element); // Handle the root node

// Finally handle complexTypes outside of the main element.
if ( isset($xsd->complexType) ) {
    foreach ( $xsd->complexType as $node ) {
        $xmlString = $node->asXML();
        $parent = new SimpleXMLElement("<parent>$xmlString</parent>");
        $parent['name'] = $node['name'];
        processNode($parent);
    }
}

/**
 * Let the funs begin here
 *
 * @param SimpleXMLElement $node Node in the XSD to be processed
 *
 * @return void
 */
function processNode( $node )
{
    global $simpleTypeData; // Rather than keep passing it through with each call

    $name = (string)$node['name'];
    $className = ucwords($name); // Classnames begine with capitals in PEAR

    // For every complex type we're going to write out a file for its class
    $fp = fopen($className.".class.php", "w");
    fwrite($fp, "<?php\n");
    $fileDocComment = <<<FILEDOCCOMMENT
/**
 * Generated by XSD2PHP
 *
 * PHP version 5.3
 *
 * @category Util
 * @package  XSD2PHP
 * @author   David Gillen <david.gillen@nexus451.com>
 * @license  GNU GPL - http://www.gnu.org/licenses/gpl.txt
 * @link     https://github.com/davidgillen/XSD2PHP
 */\n\n
FILEDOCCOMMENT;
    fwrite($fp, $fileDocComment);

    $classDocComment = <<<CLASSDOCCOMMENT
/**
 * Class representing a $className
 *
 * @category XSD2PHP
 * @package  XSD2PHP
 * @author   David Gillen <david.gillen@nexus451.com>
 * @license  GNU GPL - http://www.gnu.org/licenses/gpl.txt
 * @link     http://www.nexus451.com
 */\n
CLASSDOCCOMMENT;
    fwrite($fp, $classDocComment);
    fwrite($fp, "class $className\n{\n");

    $constructor = "\n    /**\n";
    $constructor .= "     * $className constructor\n     *\n";
    $constructor .= "##TYPEHINTS##";
    $constructor .= "     */\n";
    $constructor .= "    public function __construct(##PARAMS##)\n    {\n";
    $constructor .= "##VALIDATIONS##";
    $params = "";
    $validations = "";
    $typehints = "";

    if ( isset($node->complexType) ) {
        // To handle nested sequences - TODO: use children() on parent sequence to handle better
        // as this doesn't allow multiple squences immediately inside a sequence
        $nodeBase = isset($node->complexType->sequence->sequence)
            ? $node->complexType->sequence->sequence
            : $node->complexType->sequence;
        foreach ( $nodeBase->element as $element) {
            if ( isset($element->sequence) ) {
                // Handle sequences as their own new $node
                processNode($element->sequence);
            } elseif ( isset($element->simpleType) ) {
                // For simpleTypes defined within a complex type
                fwrite($fp, "    public $".$element['name']." = null;\n");
                $params .= '$'.$element['name']." = null, ";
                $validations .= getSimpleValidation($element, $typehints);
            } elseif ( isset($element->complexType) ) {
                // For complexType defined within another complexType
                fwrite($fp, "    public $".$element['name'].";\n");
                $params .= '$'.$element['name']." = null, ";
                $validations .= getComplexValidation($element, $typehints);
                processNode($element);
            } else {
                // Dealing with types specified outside the $root->element
                fwrite($fp, "    public $".$element['name'].";\n");
                if ( isset($simpleTypeData[ (string) $element['type'] ]) ) {
                    $params .= '$'.$element['name']." = null, ";
                    $type = (string) $element['type'];

                    // If we already pulled out data for this as a simple element
                    // Set some additional values on it, and get its validation
                    $element->simpleType->restriction['base'] = $simpleTypeData[ $type ]->restriction;
                    $element->simpleType->restriction->minLength['value']
                        = isset($simpleTypeData[ $type ]->minLength)
                        ? $simpleTypeData[ $type ]->minLength
                        : null;
                    $element->simpleType->restriction->maxLength['value']
                        = isset($simpleTypeData[ $type ]->maxLength)
                        ? $simpleTypeData[ $type ]->maxLength
                        : null;
                    $element->simpleType->restriction->pattern['value']
                        = isset($simpleTypeData[ $type ]->pattern)
                        ? $simpleTypeData[ $type ]->pattern
                        : null;
                    $validations .= getSimpleValidation($element, $typehints);
                } else {
                    // Otherwise it's a complex type.
                    $params .= '$'.$element['name']." = null, ";
                    $type = (string) $element['type'];
                    $validations .= getComplexValidation($element, $typehints);
                }
            }
        }
    } else {
        // If we see this it's something we don't yet handle
        throw new ErrorException("Unknown node type\n".print_r($node, true));
    }

    $params = substr($params, 0, -2); // Remove the final comma
    // Replace place holders from earlier and write the file to disk.
    $constructor = str_replace('##TYPEHINTS##', alignTypeHints($typehints), $constructor);
    $constructor = str_replace('##PARAMS##', $params, $constructor);
    $constructor = str_replace('##VALIDATIONS##', $validations, $constructor);
    $constructor .= "    }\n";
    fwrite($fp, $constructor);
    fwrite($fp, "}\n");
    fclose($fp);
}

/**
 * Generate code to carry out validation on a simple type
 *
 * @param SimpleXMLElement $element    Current node in XSD being processed
 * @param String           &$typehints Used later to generate doc comments
 *
 * @return String
 */
function getSimpleValidation($element, &$typehints)
{
    // Make values easier to access and set sane defaults
    $name = (string) $element['name'];
    $restriction = $element->simpleType->restriction['base'];
    $minLength = $element->simpleType->restriction->minLength['value'];
    $maxLength = $element->simpleType->restriction->maxLength['value'];
    $pattern = $element->simpleType->restriction->pattern['value'];
    $annotation = $element->annotation->documentation;
    $minOccurs = $element['minOccurs'] !== null ? (integer) $element['minOccurs'] : 1; // We assume a default of 0
    $maxOccurs = $element['maxOccurs'] !== null ? (integer) $element['maxOccurs'] : 1; // We assume a default of 1
    $validation = '';
    $typeValidation = '';

    if ( 'string' == $restriction ) {
        $typehints .= "     * @param $restriction \$$name ".'{'."$minLength, $maxLength} {$annotation}\n";
        $typeValidation .= <<<TYPEVALIDATION
        if ( strlen(\$item) < $minLength ) {
            throw new Exception('\$$name must be at least $minLength characters.');
        } elseif ( strlen(\$item) > $maxLength ) {
            throw new Exception('\$$name must be no more than $maxLength characters.');
        } else {
            \$this->$name = \$item;
        }\n
TYPEVALIDATION;
    } elseif ( 'decimal' == $restriction ) {
        $typehints .= "     * @param decimal \$$name {$annotation}\n";
        $typeValidation .= <<<TYPEVALIDATION
        if ( is_numeric(\$item) ) {
            \$this->$name = \$item;
        } else {
            throw new Exception('\$$name must be numeric.');
        }\n
TYPEVALIDATION;
    } elseif ( 'integer' == $restriction ) {
        $typehints .= "     * @param integer \$$name {$pattern} {$annotation}\n";
        $typeValidation .= <<<TYPEVALIDATION
        if ( 0 !== preg_match("/$pattern/", \$item) ) {
            \$this->$name = \$item;
        } else {
            throw new Exception('\$$name must be numeric.');
        }\n
TYPEVALIDATION;

    } else {
        throw new Exception("{$restriction} is not a recognised restriction type.");
    }

    // Special case for nulls
    $nullValidation = "";
    if ( 0 == $minOccurs ) {
        $nullValidation = <<<TYPEVALIDATION
        if ( \$item == null ) {
            // Do nothing, \$minOccurs is 0
        } else
TYPEVALIDATION;
        $typeValidation = $nullValidation . ltrim($typeValidation);
    }

    // Logic to validate the number of occurrances
    if ( $maxOccurs > 1 ) {
        // To get the indenting correct
        $validationLines = explode("\n", $typeValidation);
        foreach ( $validationLines as $key=>$val) {
            $validationLines[$key] = "    ".$val;
        }
        $typeValidation = implode("\n", $validationLines);
        $validation .= <<<VALIDATION
        if ( \$$name != null && !is_array(\$$name) ) {
            throw new Exception('\$$name must be and Array[] of $name objects.');
        }
        if ( \$$name != null && count(\$$name) > $maxOccurs ) {
            throw new Exception('\$$name can have a maximum of $maxOccurs items.');
        }
        if ( count(\$$name) < $minOccurs ) {
            throw new Exception('\$$name must have at least $minOccurs items.');
        }
        foreach ( \$$name as \$item ) {
$typeValidation;
        }\n
VALIDATION;
    } else {
        if ( 1 == $minOccurs ) {
            $validation .= <<<VALIDATION
        if ( \$$name == null ) {
            throw new Exception('\$$name must be set.');
        }\n
VALIDATION;
        }
        $validation .= <<<VALIDATION
        \$item = \$$name;
$typeValidation
VALIDATION;
    }

    return $validation;
}

/**
 *  Generate code to carry out validation on a complexType
 *
 * @param SimpleXMLElement $element    Current node in XSD being processed
 * @param String           &$typehints String to be used for Doc comments later
 *
 * @return String
 */
function getComplexValidation($element, &$typehints)
{
    // Make values easier to access and set sane defaults
    $name = (string)$element['name'];
    $className = ucwords($name);
    $type = isset($element['type']) ? ucwords($element['type']) : $className;
    $annotation = isset($element->annotation->documentation) ? (string)$element->annotation->documentation : '';
    $minOccurs = isset($element['minOccurs']) ? $element['minOccurs'] : 1;
    $maxOccurs = isset($element['maxOccurs']) ? $element['maxOccurs'] : 1;
    $validation = '';
    $optional = $minOccurs == 0 ? 'Optional' : '';
    $isArray = $maxOccurs > 1 ? '[]' : '';
    $typehints .= "     * @param $type$isArray \$$name ".'{'."$minOccurs, $maxOccurs} $optional $annotation\n";

    if ( $maxOccurs > 1 ) {
        $validation .= <<<VALIDATION
        if ( \$$name != null && !is_array(\$$name) ) {
            throw new Exception('\$$name must be an Array[] of $type objects.');
        }
        if ( \$$name != null && count(\$$name) > $maxOccurs ) {
            throw new Exception('\$$name can have a maximum of $maxOccurs items.');
        }
        if ( count(\$$name) < $minOccurs ) {
            throw new Exception('\$$name must have at least $minOccurs items.');
        }
        foreach ( \$$name as \$item ) {
            if ( get_class(\$item) != '$type' ) {
                throw new Exception('Each item in \$$name must be of type $type.');
            }
        }
        \$this->$name = \$$name;\n
VALIDATION;
    } else {
        if ( 1 == $minOccurs ) {
            $validation .= <<<VALIDATION
        if ( \$$name == null ) {
            throw new Exception('\$$name must be set.');
        }\n
VALIDATION;
        }
        $validation .= <<<VALIDATION
        if ( get_class(\$$name) != '$type' ) {
            throw new Exception('\$$name must be of type $type.');
        }\n
VALIDATION;
        $validation .= <<<VALIDATION
        \$this->$name = \$$name;\n
VALIDATION;
    }

    // Special case for nulls
    if ( 0 == $minOccurs ) {
        // To get the indenting correct
        $validationLines = explode("\n", $validation);
        foreach ( $validationLines as $key=>$val) {
            $validationLines[$key] = "    ".$val;
        }
        $validation = implode("\n", $validationLines);
            $validation = <<<NULLPOSSIBLE
        if ( \$$name == null ) {
            // Do nothing, \$minOccurs is 0
        } else {
$validation
        }\n
NULLPOSSIBLE;
    }
    return $validation;
}

/**
 * Building up associative array of the simple data types used throughout the XSD
 *
 * @param SimpleXMLElement &$xsd            The SimpleXML representation of the XSD
 * @param Array            &$simpleTypeData Details of simple types, to be used later
 *
 * @return void
 */
function extractSimpleTypeData( &$xsd, &$simpleTypeData )
{
    foreach ( $xsd->simpleType as $node ) {
        $SimpleType = new stdClass();
        $SimpleType->type = (string) $node['name'];
        $SimpleType->annotation = isset( $node->annotation->documentation ) ? (string) $node->annotation->documentation : '';

        $SimpleType->restriction = (string) $node->restriction['base'];
        switch( $SimpleType->restriction ) {
        case 'string':
            $SimpleType->minLength = (string) $node->restriction->minLength['value'];
            $SimpleType->maxLength = (string) $node->restriction->maxLength['value'];
            break;
        case 'integer':
            $SimpleType->pattern = (string) $node->restriction->pattern['value'];
            break;
        default:
            throw new Exception('Unrecognised restriction on node: '.print_r($node, true));
        }
        $simpleTypeData[ $SimpleType->type ] = clone $SimpleType;
    }
}

/**
 * We need to clean up the typehints so that each one is aligned correctly
 *
 * @param String $typeHints The original layout for the type hints
 *
 * @return String
 */
function alignTypeHints( $typeHints )
{
    // Some items will have nothing
    if ( strlen(trim($typeHints)) == 0 ) {
        return false;
    }
    $longestType = 0;
    $longestVar = 0;
    $hintsArray = array();
    $typeHints = explode("\n", trim($typeHints));

    foreach ( $typeHints as $typeHint ) {
        $currentHint = explode(" ", trim($typeHint));
        if ( strlen($currentHint[2]) > $longestType ) {
            $longestType = strlen($currentHint[2]);
        }
        if ( strlen($currentHint[3]) > $longestVar ) {
            $longestVar = strlen($currentHint[3]);
        }
        if ( !isset($currentHint[4]) ) {
            $currentHint[4] = 'No Comment...sorry.';
        }
        $hintsArray[] = $currentHint;
    }

    $hintLines = array();
    foreach ( $hintsArray as $key=>$hint ) {
        $hint[2] = str_pad($hint[2], $longestType, " ");
        $hint[3] = str_pad($hint[3], $longestVar, " ");
        $hintLines[] = "     " . implode(" ", $hint);
    }


    return implode("\n", $hintLines)."\n";
}
