/**
 * One semi-implicit Euler step of a damped spring toward `target`, with
 * stiffness `k` and damping `c`: the new position and velocity. Stable for
 * the stiffnesses used here as long as `dt` stays at or under 1/30 s.
 */
export const spring = (
    pos: number,
    vel: number,
    target: number,
    k: number,
    c: number,
    dt: number,
): [number, number] => {
    const v = vel + (k * (target - pos) - c * vel) * dt;

    return [pos + v * dt, v];
};
