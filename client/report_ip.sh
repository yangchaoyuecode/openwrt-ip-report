#!/bin/sh
# report_ip.sh
# 放在 OpenWrt 的 /usr/bin/report_ip.sh
# 定时把公网IP上报到自建PHP接口,代替被运营商屏蔽的DDNS
#
# 逻辑: 先用 ubus/network.sh 读取本地WAN口地址, 和上次缓存比较
#       - 有变化: 立即上报
#       - 无变化: 每隔 HEARTBEAT_INTERVAL 次调用才上报一次(心跳,防止服务端记录过期)
#       - 注意: 如果宽带在运营商CGNAT后面, WAN口v4地址不是真公网IP,
#               这时判断"是否变化"仍然有效(内网地址变了大概率外网也变了),
#               但真正准确的公网IP要看服务端 REMOTE_ADDR 记录的结果。

# ============ 配置区(务必修改) ============
URL="https://你的域名/update_ip.php"
TOKEN="change_this_to_a_long_random_string"
HOST="home"
CACHE_DIR="/tmp/report_ip"
HEARTBEAT_INTERVAL=12   # 配合5分钟一次的cron, 12次=1小时兜底上报一次
# ============================================

mkdir -p "$CACHE_DIR"
V4_CACHE="$CACHE_DIR/last_v4"
V6_CACHE="$CACHE_DIR/last_v6"
COUNTER_FILE="$CACHE_DIR/counter"

# ============ WAN接口配置(按你的实际环境确认过的) ============
# v4: PPPoE拨号, 运行时设备名 pppoe-wan
# v6: ISP用DHCPv6-PD下发前缀, 公网地址实际落在 br-lan 上, 不在WAN设备上
WAN_IF_V4="pppoe-wan"
WAN_IF_V6="br-lan"
# ==================================================================

CUR_V4=""
if [ -n "$WAN_IF_V4" ]; then
    CUR_V4=$(ip -4 addr show dev "$WAN_IF_V4" 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1 | head -n1)
fi

CUR_V6=""
if [ -n "$WAN_IF_V6" ]; then
    # 排除 fe80(链路本地) 和 fc/fd开头的ULA(内网本地地址), 只留公网地址
    CUR_V6=$(ip -6 addr show dev "$WAN_IF_V6" 2>/dev/null | awk '/inet6/{print $2}' | cut -d/ -f1 | grep -v '^fe80' | grep -v '^f[cd]' | head -n1)
fi

LAST_V4=$(cat "$V4_CACHE" 2>/dev/null)
LAST_V6=$(cat "$V6_CACHE" 2>/dev/null)

COUNTER=$(cat "$COUNTER_FILE" 2>/dev/null)
[ -z "$COUNTER" ] && COUNTER=0
COUNTER=$((COUNTER + 1))

V4_CHANGED=0
V6_CHANGED=0
[ -n "$CUR_V4" ] && [ "$CUR_V4" != "$LAST_V4" ] && V4_CHANGED=1
[ -n "$CUR_V6" ] && [ "$CUR_V6" != "$LAST_V6" ] && V6_CHANGED=1

if [ "$V4_CHANGED" -eq 0 ] && [ "$V6_CHANGED" -eq 0 ] && [ "$COUNTER" -lt "$HEARTBEAT_INTERVAL" ]; then
    logger -t report_ip "本地地址无变化(v4=${CUR_V4:-无} v6=${CUR_V6:-无}), 跳过上报, 计数 ${COUNTER}/${HEARTBEAT_INTERVAL}"
    echo "$COUNTER" > "$COUNTER_FILE"
    exit 0
fi

# --- 需要上报: 重置计数 ---
echo 0 > "$COUNTER_FILE"

# 上报: 服务器只有公网v4, 所以统一走IPv4连接,
# 如果本地探测到了v6地址, 通过参数一并带上(不需要v6网络能连通服务器)
if [ -n "$CUR_V4" ] || [ -n "$CUR_V6" ]; then
    QUERY="${URL}?token=${TOKEN}&host=${HOST}"
    [ -n "$CUR_V6" ] && QUERY="${QUERY}&v6=${CUR_V6}"

    RESULT=$(curl -4 -s -m 10 "$QUERY")
    if [ $? -ne 0 ] || [ -z "$RESULT" ]; then
        logger -t report_ip "上报失败: 无法连接服务器"
    else
        logger -t report_ip "本地 v4=${CUR_V4:-无} v6=${CUR_V6:-无} 上报结果: $RESULT"
        [ -n "$CUR_V4" ] && echo "$CUR_V4" > "$V4_CACHE"
        [ -n "$CUR_V6" ] && echo "$CUR_V6" > "$V6_CACHE"
    fi
fi
