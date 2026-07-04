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
