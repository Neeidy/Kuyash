<?php

declare(strict_types=1);

namespace Kuyash\Auth;

/** Outcome of a login attempt — lets the controller pick the right message. */
enum LoginResult
{
    case Ok;
    case Invalid;
    case Locked;
}
