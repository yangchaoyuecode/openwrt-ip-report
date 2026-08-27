# OpenWrt IP 上报 - DDNS 替代方案

运营商屏蔽了 DDNS 更新协议时,用这套脚本让 OpenWrt 路由器定时把公网 IP(支持 IPv4 / IPv6)"打卡上报"给你自己的 PHP 服务器,并提供一个带密码保护的网页随时查看 —— 相当于自己实现了一个极简 DDNS。

## 特性

- **支持 IPv4 / IPv6 分别记录**,互不覆盖
- **不依赖 DDNS 协议**,只是普通的 HTTP GET 请求上报,不会被针对 DDNS 协议的屏蔽策略影响
- **本地变化检测 + 心跳兜底**:IP 没变化时不会频繁请求服务器,但会定期发心跳防止记录过期
- **支持多个家庭 / 多台设备**,用 `host` 参数区分,服务端自动分文件存储
- **网页汇总查看**:密码登录后展示所有家庭的最新 IP、更新时间、在线状态
- **一键复制 IP**
- **每个家庭可配置端口**,自动生成 `http://IP:端口` 直达链接(比如访问家里的 NAS、路由器管理后台等)
- **服务器只有 IPv4 也能记录客户端的 IPv6**(客户端把本地读到的 v6 地址作为参数,通过 v4 连接一并上报)

## 目录结构

```
openwrt-ip-report/
├── client/
│   └── report_ip.sh       # 放到 OpenWrt 路由器上, 定时执行
└── server/
    ├── update_ip.php      # 接收上报, 写入记录 (路由器调用)
    ├── get_ip.php         # 查询单个 host 的 JSON 记录 (程序调用)
    └── status.php         # 网页汇总展示, 带密码登录 (人工查看)
```

## 部署

### 1. 服务端

把 `server/` 下的三个文件上传到一台**有公网可访问域名或 IP** 的 PHP 主机(虚拟主机、VPS 均可),放在同一目录下,例如:

```
https://你的域名/kd/update_ip.php
https://你的域名/kd/get_ip.php
https://你的域名/kd/status.php
```

首次有请求进来时,会在同目录自动创建 `ip_data/` 文件夹用来存放记录和日志,**无需手动创建**,但要确保 PHP 对该目录有写权限。

**必须修改的配置项:**

| 文件 | 变量 | 说明 |
|---|---|---|
| `update_ip.php` | `$SECRET_TOKEN` | 上报接口密钥,改成随机长字符串 |
| `status.php` | `$VIEW_PASSWORD` | 网页查看密码 |

`get_ip.php` 无需修改,直接读取同目录的记录文件。

> 建议给服务器配上 HTTPS(免费的 Let's Encrypt 证书即可),否则 token 和密码会以明文形式在网络上传输。

### 2. 客户端(每台 OpenWrt 路由器)

上传脚本并赋予执行权限:

```sh
scp client/report_ip.sh root@路由器IP:/usr/bin/
chmod +x /usr/bin/report_ip.sh
```

> 更推荐放在 `/etc/` 下 + 软链接的方式,防止升级固件时脚本被清空(见下方"关于恢复出厂设置 / 固件升级")。

修改脚本开头的配置区:

```sh
URL="https://你的域名/kd/update_ip.php"
TOKEN="改成和服务端一致的token"
HOST="home1"          # 每个家庭/设备用不同名字区分
WAN_IF_V4="pppoe-wan" # 需要根据实际情况确认, 见下方"如何确认接口名"
WAN_IF_V6="br-lan"    # 同上, 没有IPv6可留空
```

添加定时任务:

```sh
crontab -e
```

加入一行(每 5 分钟检测一次):

```
*/5 * * * * /usr/bin/report_ip.sh
```

也可以不进编辑器,直接一条命令追加(避免手动编辑出错):

```sh
echo '*/5 * * * * /usr/bin/report_ip.sh' >> /etc/crontabs/root
```

重启 cron 服务使其生效:

```sh
/etc/init.d/cron restart
```

确认定时任务已经写入:

```sh
crontab -l
```

**或者用 LuCI 网页后台添加(不用 SSH):**

登录路由器管理页面 → **系统(System)** → **计划任务(Scheduled Tasks)**,在文本框里加入一行:

```
*/5 * * * * /usr/bin/report_ip.sh
```

点击**保存并应用**即可,效果和命令行 `crontab -e` 完全一样(本质上改的是同一个文件 `/etc/crontabs/root`)。

### 3. 验证

```sh
# 手动执行一次(脚本本身不打印任何输出, 属正常, 所有记录写在系统日志里)
sh -x /usr/bin/report_ip.sh

# 查看日志
logread | grep report_ip
```

访问网页确认服务端收到记录:

```
https://你的域名/kd/status.php
```

## 如何确认接口名(WAN_IF_V4 / WAN_IF_V6)

不同路由器、不同上网方式(PPPoE 拨号 / DHCP 直连 / DHCPv6-PD 等)接口名不一样,不能直接照抄默认值,需要自己确认:

```sh
# 查看WAN口(v4)运行时设备名, 一般PPPoE环境下是 pppoe-wan
ifstatus wan | grep device

# 确认v4地址能正确取到
ip -4 addr show dev pppoe-wan | awk '/inet /{print $2}' | cut -d/ -f1
```

**IPv6 常见的坑**:很多 ISP 用 DHCPv6-PD(前缀委托)下发 IPv6,这种情况下公网 IPv6 地址实际上不挂在 WAN 设备上,而是被系统自动分配到了 **LAN 口(`br-lan`)**上,需要这样确认:

```sh
ip -6 addr show dev br-lan | grep -v fe80
```

看到一个不是 `fe80` 开头(链路本地)、也不是 `fc`/`fd` 开头(ULA 内网地址)的地址,那就是真正的公网 IPv6,把 `WAN_IF_V6` 设置为 `br-lan` 即可。

如果一个物理网口上同时挂了多个逻辑接口(比如既有拨号 WAN,又有光猫管理口这种静态 IP 接口),`ubus`/`network.sh` 的自动检测容易认错,建议像上面这样手动确认后**直接写死接口名**,更可靠。

## 服务器只有 IPv4 时如何记录 IPv6

如果你的服务器本身没有公网 IPv6(大多数入门级 VPS 都是这样),路由器无法通过 IPv6 连接主动"连上"服务器上报。这套方案的做法是:**IPv6 地址由路由器本地网卡读取后,作为普通参数,通过已有的 IPv4 连接一并带过去**,不需要服务器具备 v6 连通性。

对应 `update_ip.php` 里记录会带一个 `source` 字段区分可信程度:

- `source: connection` — 通过实际连接来源 IP 确认,最可靠,无法伪造
- `source: self-reported` — 客户端自己上报的(目前只用于这种服务器无 v6 的场景下的 IPv6 地址),服务器无法像验证 v4 那样交叉核实真实性

如果只有你自己的路由器知道上报用的 token,这个限制基本可以忽略。

## 关于恢复出厂设置 / 固件升级

- **固件升级(选择保留配置)**:`/etc/` 目录下的文件默认会被打进配置备份包,升级完自动还原;`/usr/bin/` 默认不在备份范围内,需要手动执行 `echo "/usr/bin/report_ip.sh" >> /etc/sysupgrade.conf` 加入备份列表,或者干脆把脚本本体放在 `/etc/` 下、`/usr/bin/` 只放一个软链接。
- **恢复出厂设置**:会清空整个可写分区,不管脚本放哪个目录都会丢失,没有例外。建议把这套代码额外保存一份在你自己的电脑或 Git 仓库里,恢复出厂后重新执行一遍部署步骤即可(几分钟的事)。

## 安全建议

- `update_ip.php` 的 `$SECRET_TOKEN` 和 `status.php` 的 `$VIEW_PASSWORD` **务必**改成随机字符串,不要用示例里的默认值
- 服务端建议配置 HTTPS
- `status.php` 页面展示的是你家里的公网 IP,注意不要把访问链接随意分享给不信任的人
- 多个家庭共用同一套服务端时,`HOST` 参数用来区分记录,请确保每个家庭设置了不同的值

## License

个人自用脚本,自由修改和分发。