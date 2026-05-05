<h2>Meross Direct Plugin Help</h2>
<p>Use <b>Configuration</b> to set your credentials and device behavior.</p>
<ul>
  <li><b>Username / Email</b>: your Meross app login email</li>
  <li><b>Password</b>: your Meross app password</li>
  <li><b>API Region</b>: <code>us</code> (North America), <code>eu</code> (Europe), or <code>ap</code> (Asia Pacific). Must match your account's region.</li>
  <li><b>Default Device UUID</b>: optional UUID used when a command does not specify a device</li>
  <li><b>Default Channel</b>: channel index for multi-outlet devices (power strips). Use <code>0</code> for single-plug devices.</li>
  <li><b>Friendly Name</b>: map a short name (like <code>Porch</code>) to a discovered device UUID + channel for use in scripts</li>
</ul>
<p>Use <b>Discover Devices</b> to pull current device UUIDs from your Meross account.</p>
<p>Use the channel buttons in the device table to set the Default Device UUID and Channel in one click.</p>
<p>Save aliases in <b>Friendly Name</b>, then use the alias in commands instead of the UUID, for example:</p>
<pre>bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh Porch on</pre>
<p>Playlist helper scripts are also available:</p>
<ul>
  <li><code>bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_on.sh [alias_or_uuid]</code></li>
  <li><code>bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_off.sh [alias_or_uuid]</code></li>
  <li><code>bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_dim.sh &lt;0-100&gt; [alias_or_uuid]</code></li>
</ul>
<p>The <b>toggle</b> and <b>status</b> actions are available from the command line:</p>
<pre>bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh Porch toggle
bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh Porch status</pre>
<p><b>Note:</b> The <code>level</code> action only works with Meross smart bulbs and dimmable devices. Regular smart plugs (MSS110, MSS210, MSS310, etc.) only support <code>on</code>, <code>off</code>, and <code>toggle</code>.</p>
<p>Use <b>Test LEVEL</b> with a 0-100 value to verify brightness control directly from the UI.</p>
