<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

/**
 * The kind of unreadability one conformance verdict names, so a deployment gate branches on a value
 * instead of matching the sentence riding beside it. Each kind points at a different repair:
 *
 * - `UnknownType` and `NotAnEvent`: no declared domain event answers the stored type, so a
 *   declaration is what is missing and no version is in question.
 *
 * - `AboveRuntime`: rows sit higher than this binary knows, the rolled-back deploy; rolling the
 *   binary FORWARD is the repair, never an upcaster.
 *
 * - `MissingStep` and `DuplicatedStep`: between the stored floor and the current version a step has
 *   no owner or two, so an upcaster is owed or one too many is registered.
 *
 * - `UpcasterFailed`: an upcaster's own probe threw, so the chain cannot say what it owns and the
 *   steps of that type were never judged.
 *
 * The kinds and the families `UpcasterConformance` reports are one set, in both directions: a
 * verdict with no kind cannot be typed, and a kind no verdict reaches promises a branch a consumer
 * will never take.
 *
 * The backing value is the wire form, snake_case, part of the `storm:upcaster:verify --json`
 * contract.
 */
enum AnomalyKind: string
{
    case UnknownType = 'unknown_type';

    case NotAnEvent = 'not_an_event';

    case AboveRuntime = 'above_runtime';

    case UpcasterFailed = 'upcaster_failed';

    case MissingStep = 'missing_step';

    case DuplicatedStep = 'duplicated_step';
}
