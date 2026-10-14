import type { LucideIcon } from '@lucide/vue';
import {
    Boxes,
    CarFront,
    DoorOpen,
    Presentation,
    Projector,
} from '@lucide/vue';

const NAMED: Record<string, LucideIcon> = {
    presentation: Presentation,
    car: CarFront,
    'car-front': CarFront,
    'door-open': DoorOpen,
    projector: Projector,
};

/**
 * Icon for a resource type: its stored icon name when we ship a matching
 * glyph, otherwise a guess from the name, otherwise a neutral box.
 */
export function resourceTypeIcon(type: {
    icon?: string | null;
    name: string;
}): LucideIcon {
    if (type.icon && NAMED[type.icon]) {
        return NAMED[type.icon];
    }

    if (/car|vehicle|van|shuttle|pool|transport/i.test(type.name)) {
        return CarFront;
    }

    if (/room|board|hall|conference|meeting|venue/i.test(type.name)) {
        return DoorOpen;
    }

    if (/equipment|projector|gear|device/i.test(type.name)) {
        return Projector;
    }

    return Boxes;
}

/**
 * What a type's resources are called in copy ("Book a room", "3 of 5
 * vehicles free").
 */
export function resourceNouns(type: { icon?: string | null; name: string }): {
    one: string;
    many: string;
    action: string;
} {
    const icon = resourceTypeIcon(type);

    if (icon === CarFront) {
        return {
            one: 'vehicle',
            many: 'vehicles',
            action: 'Book a vehicle',
        };
    }

    if (icon === DoorOpen || icon === Presentation) {
        return {
            one: 'room',
            many: 'rooms',
            action: 'Book a room',
        };
    }

    return {
        one: 'item',
        many: 'items',
        action: 'New booking',
    };
}

/** The views a booking calendar offers. */
export const BOOKING_VIEWS = ['day', 'week', 'month'] as const;

export type BookingView = (typeof BOOKING_VIEWS)[number];
