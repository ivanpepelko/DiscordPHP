<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016 David Cole <david@team-reflex.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Parts\Guild;

use Discord\Exceptions\DiscordRequestFailedException;
use Discord\Exceptions\PartRequestFailedException;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Part;
use Discord\Parts\Permissions\RolePermission as Permission;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Illuminate\Support\Collection;

/**
 * A Guild is Discord's equivalent of a server. It contains all the Members, Channels, Roles, Bans etc.
 */
class Guild extends Part
{
    const REGION_DEFAULT    = self::REGION_US_WEST;

    const REGION_US_WEST    = 'us-west';

    const REGION_US_SOUTH   = 'us-south';

    const REGION_US_EAST    = 'us-east';

    const REGION_US_CENTRAL = 'us-central';

    const REGION_SINGAPORE  = 'singapore';

    const REGION_LONDON     = 'london';

    const REGION_SYDNEY     = 'sydney';

    const REGION_FRANKFURT  = 'frankfurt';

    const REGION_AMSTERDAM  = 'amsterdam';

    const LEVEL_OFF         = 0;

    const LEVEL_LOW         = 1;

    const LEVEL_MEDIUM      = 2;

    const LEVEL_TABLEFLIP   = 3;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'id',
        'name',
        'icon',
        'region',
        'owner_id',
        'roles',
        'joined_at',
        'afk_channel_id',
        'afk_timeout',
        'embed_enabled',
        'embed_channel_id',
        'features',
        'splash',
        'emojis',
        'large',
        'verification_level',
        'member_count',
    ];

    /**
     * {@inheritdoc}
     */
    protected $uris = [
        'get'    => 'guilds/:id',
        'create' => 'guilds',
        'update' => 'guilds/:id',
        'delete' => 'guilds/:id',
        'leave'  => 'users/@me/guilds/:id',
    ];

    /**
     * An array of valid regions.
     *
     * @var array Array of valid regions.
     */
    protected $regions = [
        self::REGION_US_WEST,
        self::REGION_US_SOUTH,
        self::REGION_US_EAST,
        self::REGION_US_CENTRAL,
        self::REGION_LONDON,
        self::REGION_SINGAPORE,
        self::REGION_SYDNEY,
        self::REGION_FRANKFURT,
        self::REGION_AMSTERDAM,
    ];

    /**
     * Leaves the guild.
     *
     * Does not leave the guild if you are the owner however, please use
     * delete() for that.
     *
     * @throws PartRequestFailedException
     *
     * @return bool Whether the attempt to leave succeeded or failed.
     *
     * @see \Discord\Parts\Part::delete() Used for leaving/deleting the guild if you are owner.
     */
    public function leave()
    {
        try {
            $this->guzzle->delete($this->replaceWithVariables($this->uris['leave']));
            $this->created = false;
            $this->deleted = true;
        } catch (\Exception $e) {
            throw new PartRequestFailedException($e->getMessage());
        }

        return true;
    }

    /**
     * Transfers ownership of the guild to
     * another member.
     *
     * @param Member|int $member The member to transfer ownership to.
     *
     * @return bool Whether the attempt succeeded or failed.
     */
    public function transferOwnership($member)
    {
        if ($member instanceof Member) {
            $member = $member->id;
        }

        try {
            $request = $this->guzzle->patch(
                $this->replaceWithVariables('guilds/:id'),
                [
                    'owner_id' => $member,
                ]
            );

            if ($request->owner_id != $member) {
                return false;
            }

            $this->fill((array) $request);
        } catch (DiscordRequestFailedException $e) {
            return false;
        }

        return true;
    }

    /**
     * Returns the guilds members.
     *
     * @return Collection A collection of members.
     */
    public function getMembersAttribute()
    {
        if ($members = $this->cache->get("guild.{$this->id}.members")) {
            return $members;
        }

        // Members aren't retrievable via REST anymore,
        // they will be set if the websocket is used.
        $this->cache->set("guild.{$this->id}.members", new Collection());

        return $this->cache->get("guild.{$this->id}.members");
    }

    /**
     * Returns the guilds roles.
     *
     * @return Collection A collection of roles.
     */
    public function getRolesAttribute()
    {
        if (isset($this->attributes_cache['roles'])) {
            return $this->attributes_cache['roles'];
        }

        if ($roles = $this->cache->get("guild.{$this->id}.roles")) {
            return $roles;
        }

        $roles = [];

        foreach ($this->attributes['roles'] as $index => $role) {
            $perm                = $this->partFactory->create(Permission::class);
            $perm->perms         = $role->permissions;
            $role                = (array) $role;
            $role['permissions'] = $perm;
            $role['guild_id']    = $this->id;
            $roles[$index]       = $this->partFactory->create(Role::class, $role, true);
        }

        $roles = new Collection($roles);

        $this->cache->set("guild.{$this->id}.roles", $roles);

        return $roles;
    }

    /**
     * Returns the owner.
     *
     * @return User An User part.
     */
    public function getOwnerAttribute()
    {
        if ($owner = $this->cache->get("user.{$this->owner_id}")) {
            return $owner;
        }

        $request = $this->guzzle->get($this->replaceWithVariables('users/:owner_id'));

        $owner = $this->partFactory->create(User::class, $request, true);

        $this->cache->set("user.{$user->id}", $owner);

        return $owner;
    }

    /**
     * Returns the guilds channels.
     *
     * @return Collection A collection of channels.
     */
    public function getChannelsAttribute()
    {
        $key = "guild.{$this->id}.channels";
        if ($this->cache->has($key)) {
            return $this->cache->get($key);
        }

        $channels = [];
        $request  = $this->guzzle->get($this->replaceWithVariables('guilds/:id/channels'));

        foreach ($request as $index => $channel) {
            $channel = $this->partFactory->create(Channel::class, $channel, true);
            $this->cache->set("channel.{$channel->id}", $channel);
            $channels[$index] = $channel;
        }

        $channels = new Collection($channels);

        $this->cache->set($key, $channels);

        return $channels;
    }

    /**
     * Returns the guilds bans.
     *
     * @return Collection A collection of bans.
     */
    public function getBansAttribute()
    {
        if ($bans = $this->cache->get("guild.{$this->id}.bans")) {
            return $bans;
        }

        $bans = [];

        try {
            $request = $this->guzzle->get($this->replaceWithVariables('guilds/:id/bans'));
        } catch (DiscordRequestFailedException $e) {
            return new Collection();
        }

        foreach ($request as $index => $ban) {
            $ban          = (array) $ban;
            $ban['guild'] = $this;
            $ban          = $this->partFactory->create(Ban::class, $ban, true);
            $this->cache->set("guild.{$this->id}.bans.{$ban->user_id}", $ban);
            $bans[$index] = $ban;
        }

        $bans = new Collection($bans);

        $this->cache->set("guild.{$this->id}.bans", $bans);

        return $bans;
    }

    /**
     * Returns the guilds invites.
     *
     * @return Collection A collection of invites.
     */
    public function getInvitesAttribute()
    {
        if (isset($this->attributes_cache['invites'])) {
            return $this->attributes_cache['invites'];
        }

        if ($invites = $this->cache->get("guild.{$this->id}.invites")) {
            return $invites;
        }

        $request = $this->guzzle->get($this->replaceWithVariables('guilds/:id/invites'));
        $invites = [];

        foreach ($request as $index => $invite) {
            $invite = $this->partFactory->create(Invite::class, $invite, true);
            $this->cache->set("invite.{$invite->id}", $invite);
            $invites[$index] = $invite;
        }

        $invites = new Collection($invites);

        $this->cache->set("guild.{$this->id}.invites", $invites);

        return $invites;
    }

    /**
     * Returns the guilds icon.
     *
     * @return string|null The URL to the guild icon or null.
     */
    public function getIconAttribute()
    {
        if (is_null($this->attributes['icon'])) {
            return;
        }

        return "https://discordapp.com/{$this->attributes['id']}/icons/{$this->attributes['icon']}.jpg";
    }

    /**
     * Returns the guild icon hash.
     *
     * @return string|null The guild icon hash or null.
     */
    public function getIconHashAttribute()
    {
        return $this->attributes['icon'];
    }

    /**
     * Returns the guild splash.
     *
     * @return string|null The URL to the guild splash or null.
     */
    public function getSplashAttribute()
    {
        if (is_null($this->attributes['splash'])) {
            return;
        }

        return "https://discordapp.com/api/guilds/{$this->id}/splashes/{$this->attributes['splash']}.jpg";
    }

    /**
     * Returns the guild splash hash.
     *
     * @return string|null The guild splash hash or null.
     */
    public function getSplashHashAttribute()
    {
        return $this->attributes['splash'];
    }

    /**
     * Validates the specified region.
     *
     * @return string Returns the region if it is valid or default.
     *
     * @see self::REGION_DEFAULT The default region.
     */
    public function validateRegion()
    {
        if (!in_array($this->region, $this->regions)) {
            return self::REGION_DEFUALT;
        }

        return $this->region;
    }

    /**
     * {@inheritdoc}
     */
    public function setCache($key, $value)
    {
        $this->cache->set("guild.{$this->id}.{$key}", $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getCreatableAttributes()
    {
        return [
            'name'   => $this->name,
            'region' => $this->validateRegion(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUpdatableAttributes()
    {
        return [
            'name'               => $this->name,
            'region'             => $this->region,
            'logo'               => $this->logo,
            'splash'             => $this->splash,
            'verification_level' => $this->verification_level,
            'afk_channel_id'     => $this->afk_channel_id,
            'afk_timeout'        => $this->afk_timeout,
        ];
    }
}
