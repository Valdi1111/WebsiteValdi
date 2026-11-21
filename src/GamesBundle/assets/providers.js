// Composition root of the games SPA: the only module that knows the provider bundles.
// Import it only from app.js; shared components read the current provider via useGameProvider().
import gryphline from "@GryphlineBundle/provider";
import hoyoverse from "@HoyoverseBundle/provider";
import perfectworld from "@PerfectworldBundle/provider";

export default [hoyoverse, gryphline, perfectworld];
