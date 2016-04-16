<?php

namespace Kevintweber\HtmlTokenizer\Tokens;

use Kevintweber\HtmlTokenizer\Exceptions\ParseException;

class Element extends AbstractToken
{
    /** @var array[Token] */
    private $attributes;

    /** @var array[Token] */
    private $children;

    /** @var string */
    private $name;

    public function __construct(Token $parent = null, $throwOnError = false)
    {
        parent::__construct(Token::ELEMENT, $parent, $throwOnError);

        $this->attributes = array();
        $this->children = array();
        $this->name = null;
    }

    /**
     * Does the parent have an implied closing tag?
     *
     * @param string $html
     *
     * @return boolean
     */
    public function isClosingElementImplied($html)
    {
        $parent = $this->getParent();
        if ($parent === null || !($parent instanceof self)) {
            return false;
        }

        $name = $this->parseElementName($html);
        $parentName = $parent->getName();

        // HEAD: no closing tag.
        if ($name === 'body' && $parentName === 'head') {
            return true;
        }

        // P
        $elementsNotChildrenOfP = array(
            'address',
            'article',
            'aside',
            'blockquote',
            'details',
            'div',
            'dl',
            'fieldset',
            'figcaption',
            'figure',
            'footer',
            'form',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'header',
            'hgroup',
            'hr',
            'main',
            'menu',
            'nav',
            'ol',
            'p',
            'pre',
            'section',
            'table',
            'ul'
        );
        if ($parentName === 'p' && array_search($name, $elementsNotChildrenOfP) !== false) {
            return true;
        }

        // LI
        if ($parentName == 'li' && $name == 'li') {
            return true;
        }

        // DT and DD
        if (($parentName == 'dt' || $parentName == 'dd') && ($name == 'dt' || $name == 'dd')) {
            return true;
        }

        // RP and RT
        if (($parentName == 'rp' || $parentName == 'rt') && ($name == 'rp' || $name == 'rt')) {
            return true;
        }

        return false;
    }

    /**
     * Will parse this element.
     *
     * @param string $html
     *
     * @return string Remaining HTML.
     */
    public function parse($html)
    {
        $this->name = $this->parseElementName($html);

        // Parse attributes.
        $remainingHtml = substr($html, strlen($this->name) + 1);
        while (strpos($remainingHtml, '>') !== false && preg_match("/^\s*[\/]?>/", $remainingHtml) === 0) {
            $remainingHtml = $this->parseAttribute(trim($remainingHtml));
        }

        // Find position of end of tag.
        $posOfClosingBracket = strpos($remainingHtml, '>');
        if ($posOfClosingBracket === false) {
            if ($this->getThrowOnError()) {
                throw new ParseException('Invalid element: missing closing bracket.');
            }

            return '';
        }

        // Is self-closing?
        $posOfSelfClosingBracket = strpos($remainingHtml, '/>');
        $remainingHtml = trim(substr($remainingHtml, $posOfClosingBracket + 1));
        if ($posOfSelfClosingBracket !== false && $posOfSelfClosingBracket == $posOfClosingBracket - 1) {
            // Self-closing element.
            return $remainingHtml;
        }

        // Lets close those closed-only elements that are left open.
        $closedOnlyElements = array(
            'base',
            'link',
            'meta',
            'hr',
            'br'
        );
        if (array_search($this->name, $closedOnlyElements) !== false) {
            return $remainingHtml;
        }

        // Open element.
        return $this->parseContents($remainingHtml);
    }

    /**
     * Will parse attributes.
     *
     * @param string $html
     *
     * @return string Remaining HTML.
     */
    private function parseAttribute($html)
    {
        $remainingHtml = trim($html);

        // Will match the first entire name/value attribute pair.
        preg_match(
            "/((([a-z0-9\-_]+:)?[a-z0-9\-_]+)(\s*=\s*)?)/i",
            $remainingHtml,
            $attributeMatches
        );

        $name = $attributeMatches[2];
        $remainingHtml = substr(strstr($remainingHtml, $name), strlen($name));
        if (preg_match("/^\s*=\s*/", $remainingHtml) === 0) {
            // Valueless attribute.
            $this->attributes[trim($name)] = true;
        } else {
            $remainingHtml = ltrim($remainingHtml, ' =');
            if ($remainingHtml[0] === "'" || $remainingHtml[0] === '"') {
                // Quote enclosed attribute value.
                $valueMatchSuccessful = preg_match(
                    "/" . $remainingHtml[0] . "(.*?(?<!\\\))" . $remainingHtml[0] . "/s",
                    $remainingHtml,
                    $valueMatches
                );
                if ($valueMatchSuccessful !== 1) {
                    if ($this->getThrowOnError()) {
                        throw new ParseException('Invalid value encapsulation.');
                    }

                    return '';
                }

                $value = $valueMatches[1];
            } else {
                // No quotes enclosing the attribute value.
                preg_match("/(\s*([^>\s]*(?<!\/)))/", $remainingHtml, $valueMatches);
                $value = $valueMatches[2];
            }

            $this->attributes[trim($name)] = $value;

            // Determine remaining html.
            $posOfAttributeValue = strpos($html, $value);
            $remainingHtml = trim(
                substr($html, $posOfAttributeValue + strlen($value))
            );
            $remainingHtml = ltrim($remainingHtml, '\'"/ ');
        }

        return $remainingHtml;
    }

    /**
     * Will parse the contents of this element.
     *
     * @param string $html
     *
     * @return string Remaining HTML.
     */
    private function parseContents($html)
    {
        $remainingHtml = trim($html);
        if ($remainingHtml == '') {
            return '';
        }

        // Nothing to parse inside a script tag.
        if ($this->name == 'script') {
            return $this->parseScriptContents($remainingHtml);
        }

        // Parse contents one token at a time.
        while (preg_match("/^<\/\s*" . $this->name . "\s*>/is", $remainingHtml) === 0) {
            $token = TokenFactory::buildFromHtml(
                $remainingHtml,
                $this,
                $this->getThrowOnError()
            );

            if ($token === false || $token->isClosingElementImplied($remainingHtml)) {
                return $remainingHtml;
            }

            $remainingHtml = trim($token->parse($remainingHtml));
            $this->children[] = $token;
        }

        // Remove remaining closing tag.
        $posOfClosingBracket = strpos($remainingHtml, '>');

        return substr($remainingHtml, $posOfClosingBracket + 1);
    }

    /**
     * Will get the element name from the html string.
     *
     * @param $html string
     *
     * @return string The element name.
     */
    private function parseElementName($html)
    {
        $elementMatchSuccessful = preg_match(
            "/^(<(([a-z0-9\-]+:)?[a-z0-9\-]+))/i",
            $html,
            $elementMatches
        );
        if ($elementMatchSuccessful !== 1) {
            if ($this->getThrowOnError()) {
                throw new ParseException('Invalid element name.');
            }

            return '';
        }

        return strtolower($elementMatches[2]);
    }

    /**
     * Will parse the script contents correctly.
     *
     * @param $html string
     *
     * @return string The remaining HTML.
     */
    private function parseScriptContents($html)
    {
        $remainingHtml = trim($html);

        $matchingResult = preg_match("/(<\/\s*script\s*>)/i", $html, $endOfScriptMatches);
        if ($matchingResult === 0) {
            $value = $remainingHtml;
            $remainingHtml = '';
        } else {
            $closingTag = $endOfScriptMatches[1];
            $value = trim(
                substr($remainingHtml, 0, strpos($remainingHtml, $closingTag))
            );
            $remainingHtml = substr(
                strstr($remainingHtml, $closingTag),
                strlen($closingTag)
            );
        }

        // Handle no contents.
        if ($value == '') {
            return $remainingHtml;
        }

        $text = new Text($this, $this->getThrowOnError(), $value);
        $this->children[] = $text;

        return $remainingHtml;
    }

    /**
     * Getter for 'attributes'.
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * @return boolean
     */
    public function hasAttributes()
    {
        return !empty($this->attributes);
    }

    /**
     * Getter for 'children'.
     *
     * @return array
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @return boolean
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }

    /**
     * Getter for 'name'.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    public function toArray()
    {
        $result = array(
            'type' => 'element',
            'name' => $this->name
        );

        if (!empty($this->attributes)) {
            $result['attributes'] = array();
            foreach ($this->attributes as $name => $value) {
                $result['attributes'][$name] = $value;
            }
        }

        if (!empty($this->children)) {
            $result['children'] = array();
            foreach ($this->children as $child) {
                $result['children'][] = $child->toArray();
            }
        }

        return $result;
    }
}
