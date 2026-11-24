import { Flex, Tag, Tooltip, Typography } from "antd";
import React from "react";

// Site-wide admin role: the users admin page needs it (AdminUserManager::ADMIN_ROLE)
export const ADMIN_ROLE = 'ROLE_ADMIN';

// Assigned roles stand out, inherited ones keep the neutral tag color, as in the profile's RolesCard
const ASSIGNED_COLOR = 'blue';

/**
 * Select options of the roles, one group per bundle in the backend order (site-wide roles first), named as in the
 * profile's RolesCard.
 *
 * @param {import("./AdminApi").AdminRole[]} roles
 * @param {string[]} locked roles shown but not removable (disabled options)
 */
export function toRoleOptions(roles, locked = []) {
    const groups = new Map();
    for (const role of roles) {
        const key = role.bundle ?? '';
        if (!groups.has(key)) {
            groups.set(key, { key: key || 'general', label: role.bundle ? role.bundle.replace(/Bundle$/, '') : 'General', options: [] });
        }
        // A plain string label: it is also what the selected tags show
        groups.get(key).options.push({ value: role.name, label: role.label, disabled: locked.includes(role.name) });
    }

    return [...groups.values()];
}

/**
 * The roles a set of assigned roles reaches through the hierarchy (each role's "implies"), sorted: what the backend
 * computes for "effective_roles", for a set being edited.
 *
 * @param {string[]} assigned
 * @param {Object<string, import("./AdminApi").AdminRole>} rolesByName
 */
export function effectiveRoleNames(assigned, rolesByName) {
    const names = new Set(assigned);
    for (const name of assigned) {
        for (const implied of rolesByName[name]?.implies ?? []) {
            names.add(implied);
        }
    }

    return [...names].sort();
}

/**
 * Assigned roles first (blue), then the inherited ones (neutral), each with its code in the tooltip.
 *
 * @param {Object} props
 * @param {string[]} props.assigned
 * @param {string[]} props.effective assigned and inherited
 * @param {Object<string, import("./AdminApi").AdminRole>} props.rolesByName
 */
export function RoleTags({ assigned, effective, rolesByName }) {
    const inherited = effective.filter(name => !assigned.includes(name));
    const byLabel = (a, b) => (rolesByName[a]?.label ?? a).localeCompare(rolesByName[b]?.label ?? b);

    if (effective.length === 0) {
        return <Typography.Text type="secondary">No roles</Typography.Text>;
    }

    return <Flex wrap gap={6}>
        {[...assigned.toSorted(byLabel), ...inherited.toSorted(byLabel)].map(name => {
            const isAssigned = assigned.includes(name);

            return <Tooltip key={name} title={`${name} (${isAssigned ? 'assigned' : 'inherited'})`}>
                <Tag color={isAssigned ? ASSIGNED_COLOR : undefined} style={{ marginInlineEnd: 0 }}>
                    {rolesByName[name]?.label ?? name}
                </Tag>
            </Tooltip>;
        })}
    </Flex>;
}

/**
 * The legend of RoleTags.
 */
export function RoleTagsLegend() {
    return <Flex gap="small">
        <Tag color={ASSIGNED_COLOR} style={{ marginInlineEnd: 0 }}>Assigned</Tag>
        <Tag style={{ marginInlineEnd: 0 }}>Inherited</Tag>
    </Flex>;
}
