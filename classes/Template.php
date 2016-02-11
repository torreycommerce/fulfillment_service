<?php

class Template {
  private $twig;

  public function __construct() {
    $loader = new Twig_Loader_String();
    $this->twig = new Twig_Environment($loader);
  }

    public function render($template, $attr) {
      return $this->twig->loadTemplate($template)->render($attr);
    }
}

?>
