---
title: 'Traits vs Interfaces vs Abstract Classes in PHP'
description: Difference between Traits, Interfaces, and Abstract Classes, including the latest features from PHP 8.
date: January 11, 2022
slug: 'reference-traits-interfaces-abstract-classes-php-8'
tags:
- PHP
- OOP
---

You can `use` traits `implement` interfaces and `extend` abstract classes.<br>
Here are some notes on <u>how they are different</u> and how to use them for <u>better code design</u>.

<br>

> ## TLDR;
> Use Interfaces as a [Public Contract](#thinking-interfaces);<br>
> Use Abstract Classes as a [Private Contract](#thinking-abstract-classes);<br>
> Use Traits as [Class Extensions](#thinking-traits);<br>
> Traits will [override](#traits-vs-abstract-classes) abstract class methods;

<br>

# Thinking in OOP
Here are some ways to think of traits, interfaces, and abstract classes to write better php—

## Thinking Interfaces
> - Interface === Type
> - Use it to declare a **public contract**

It is a **type**, a new type of object. An abstract type.<br>
It is **similar to an abstract class but**
- You can <u>implement multiple  Interfaces</u> but only <u>extend one abstract class</u>
- <u>Constants</u> defined by an Interface <u>are immutable</u> (before PHP 8.1)
- Interfaces can only have <u>public methods and constants</u>.
- Interfaces <u>cannot have properties</u>. Abstract classes can.

It is not a contract, but we can **make it a contract by** pre-declaring its **public behavior**


## Thinking Abstract Classes
> - Use it to declare a **private contract**

While Interfaces declare a public contract, **abstract classes can declare a private contract**
- Use protected methods to decalre its private contract
- Abstract classes <u>cannot have private methods</u>.


## Thinking Traits
> - Traits are just like classes
> - Use it as class extensions

A trait can do _anything a normal php class can do_ **except**
- traits <u>cannot define a constant</u>
- traits <u>cannot extend another trait</u>

<div class="box php">
Starting In <em>PHP 8.0</em>, Traits can now have <code>abstract private</code> methods.<br>
<a href="#new-in-php-8">Read more in this section</a>
</div>

### Extra notes on Traits
- Methods in a trait are **overwritable**. You can change
    - the visibility (e.g. public to private)
    - the parameters
    - the return type
    - even the name
      ```php
      use MyTrait {
          MyTrait::method as private differentMethodName;
          MyTrait::doSomething as public reallyDoSomething;
      }
      ```

- All data/properties in a Trait are **calculated at runtime**<br>
    - They are not compiled.<br>
    - e.g. `__CLASS__` will always be the class that is using the Trait.



# Who wins?
## Traits vs Abstract Classes
Trait methods *will override* methods in an Abstract Class.
```php
trait MyTrait
{
    public function doSomething()
    {
        return 'trait wins!';
    }
}

abstract class MyAbstractClass
{
    public function doSomething()
    {
        return 'abstract class wins!';
    }
}

class MyClass extends MyAbstractClass
{
    use MyTrait;
}


$test = new MyClass();
echo $test->doSomething();
// This prints 'trait wins!'
```



# New in PHP 8
Starting In **PHP 8.0**, <u>Traits can now have `abstract private` methods</u>.
- These  abstract private methods are *not overwritable*.
- These `abstract private` methods can be *used as contracts*.
- You cannot have `abstract private` in an abstract class.

Starting In **PHP 8.1**, <u>You can override constants defined by Interfaces</u>.
