---
title: 'Reference: Traits vs Interfaces vs Abstract Classes'
description: A Reference for Traits, Interfaces, and Abstract Classes, with the latest features from PHP 8 included.
date: January 11, 2022
slug: 'reference-traits-interfaces-abstract-classes-php-8'
tags:
- PHP
---

You can `use` traits `implement` interfaces and `extend` abstract classes.
_Whats the difference?_


# Thinking in OOP
Here are some ways to think of traits, interfaces, and abstract classes to write better php.

### Interfaces in OOP
Think of Interfaces as a **type**, a new type of object. An **abstract** type. It is not a contract, but we can **make it a contract** by pre-declaring its **public behavior**.

### Abstract Classes in OOP
While Interfaces declare a public contract, **abstract classes can declare a private contract**, using abstract protected methods (abstract classes cannot have `abstract private` methods).

### Traits in OOP
A `trait` can do anything that a `class` can do **BUT**
- cannot define a `constant`
- cannot extend another `trait`

Starting In **PHP 8.0**, Traits can now have `abstract private` methods
- These  abstract private methods are *not overwritable*.
- These `abstract private` methods can be *used as contracts*.
- You cannot have `abstract private` in an abstract class.

# Important Differences
### Interfaces vs Abstract Classes
- You can implement **multiple  Interfaces** but only extend **one abstract class**
- Constants defined by an Interface is **immutable** (before PHP 8.1)
- Interfaces can only have **public** methods and constants.
- Interfaces cannot have **properties**. Abstract classes can.

### Traits vs Abstract Classes
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



# Extra
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



> ## TLDR;
> Use Interfaces as a [Public Contract](#interfaces-in-oop);<br>
> Use Abstract Classes as a [Private Contract](#abstract-classes-in-oop);<br>
> Use Traits as [Class Extensions](#traits-in-oop);<br>
> Trait methods will [override](#traits-vs-abstract-classes) abstract class methods;

