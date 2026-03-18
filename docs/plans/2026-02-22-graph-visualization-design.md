# 2026-02-22-graph-visualization-design

## Overview
Redesigning the native SVG graph in `assets/admin.js` to address visual clutter, node overlapping, and poor silo distinction without relying on heavy external libraries like D3.js or Vis.js.

## The Problem
- The custom physics engine clusters nodes too tightly together.
- Text labels are always visible, creating a "text soup" (gloubi-boulga) of unreadable overlapping text.
- Silos (community clusters) lack visual distinction or bounding areas.

## The Solution: A Hybrid "Clean & Contained" Approach
We will combine **Option 1 (Fast visual clean-up)** and **Option 2 (Silo Bubbles)** into a single, cohesive SVG architecture.

### 1. The "Silo Halo" (Les Bulles de confinement)
- For every detected topic cluster (Silo), we will draw a large background SVG `<circle>` (or `<path>`) acting as a "Halo" or "Hub".
- This halo will have a very faint background color corresponding to the cluster's base color (opacity ~5-10%).
- The halo will visually group all nodes belonging to that silo.

### 2. Physics & Spacing Tweaks
- **Repulsion Boost:** Increase the negative charge (repulsion force) between individual nodes so they naturally push each other away, avoiding bubble overlap.
- **Center Attraction Tweaks:** Nodes will be pulled towards their specific Silo's center coordinates rather than the absolute center of the entire canvas.
- **Silo Spreading:** Ensure the Silo centers themselves are spaced far apart from each other across the canvas layout.

### 3. Progressive Disclosure (Decluttering Text)
- **Hide by default:** Node labels (`<text>`) will be set to `display: none` or `opacity: 0` by default.
- **Hover interactions:**
  - Hovering over a dot (node) will reveal its text label.
  - Clicking on a node still opens the side panel as usual.
- **Hover on Silo:** Hovering over a Silo's background Halo could gently dim all other silos to focus on the current cluster.

## Implementation Steps
1. Modify `renderGraphSVG()` in `assets/admin.js`.
2. Extract the bounding box coordinates for each Silo after the physics simulation completes.
3. Draw `<circle>` elements representing the halos *behind* the links and nodes.
4. Modify the `<text>` element generation to add CSS classes for hover states, removing the default visible text.
5. Re-tune the `vx` and `vy` loop parameters in the simple physics section.

## Trade-offs
- The physics engine is still O(150 iterations * N^2) which might be slow for > 500 nodes, but we keep the plugin dependency-free.
- Halo circles might overlap dynamically, but they will still provide a strong visual cue.
