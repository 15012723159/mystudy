前言
在业务中，会经常使用 Redis 作为后端缓存、存储。如果结构规划不合理、命令使用不规范，会造成系统性能达到瓶颈、活动高峰系统可用性下降，也会增大运维难度。为了避免出现因 Redis 使用不当，而造成异常影响业务，以及方便后期运维，故而经团队内部人员协商，出具 Redis 使用规范，通过规范更好、更高效，更安全的使用 Redis 缓存。本规范从键值设计、缓存设计、命令使用、客户端使用、相关工具等方面进行说明。

诟病
Redis 命名不规范，各种命名规则混合使用

Redis 被用于持久化存储数据，Redis 数据有丢失风险，无重新加载方案

Redis 存储的 key，未设置过期时间

存储选型
Redis 是一个单进程、基于内存、弱事务(单个命令可以保证原子性，多命令无法保证)的 NoSQL 存储系统，适用于高 QPS、低迟延、弱持久化的场景，适宜用作缓存。

从经验出发：在 QPS>5000、容量 < 50G、存储高频数据时考虑；在 QPS<1000、存储大量低频数据、需要事务时考虑 MySQL。

使用场景
高并发场景下，热点数据缓存高并发场景下，合理的使用缓存不仅能够提升网站访问速度，还能降低后端数据库的压力。

排行榜类场景关系型数据库在排行榜类场景的查询速度普遍偏慢，借助 Redis 提供的 list 和 sorted sets 结构能实现各种复杂的排行榜应用。

限时业务的运用利用 expire 命令可以运用在限时优惠活动信息、订单库存过期、手机验证码等业务场景。

计数器 Redis 天然支持计数功能而且计数的性能也非常好，在高并发场景下优于传统的关系型数据库，常运用于商品的浏览数、视频的播放数、限制调用等。

社交网络点赞、踩、关注 / 被关注、共同好友等是社交网站的基本功能，社交网站的访问量通常来说比较大，而且传统的关系数据库类型不适合存储这种类型的数据，Redis 提供的哈希、集合等数据结构能很方便的实现这些功能。

分布式锁在高并发场景中，利用数据库锁来控制资源的并发访问，性能不理想，可以利用 Redis 的 setnx 功能来编写分布式的锁。

键值设计
Redis key 命名应该具有可读性、可管理性和简洁性，不该使用含义不清的 key 以及特别长的 key 名；user:{uid}:friends:messages:{mid}:string
u:{uid}:fr:m:{mid}:string // 简化后

控制 key 的总数量 Redis 实例包含的键个数建议控制在 1 千万内，单实例的键个数过大，可能导致过期键的回收不及时。

Redis key 命名必须以 key 所代表的 value 类型结尾，见到 key 即可知道存储数据类型，以提高可读性；

Redis key 命名必须按照模块区分前缀，具体模块定义参照上述模块划分中的内容，逻辑含义段必须使用英文半角冒号(:)分割，单词之间必须使用英文半角点号(.)分割，一定不可使用特殊字符(下划线、空格、换行、单双引号以及其他转义字符等)；

拒绝 bigkey(防止网卡流量、慢查询)string 类型控制在 10KB 以内，hash、list、set、zset 元素个数不要超过 5000。反例：一个包含 200 万个元素的 list。非字符串的 bigkey，不要使用 del 删除，使用 hscan、sscan、zscan 方式渐进式删除，同时要注意防止 bigkey 过期时间自动删除问题(例如一个 200 万的 zset 设置 1 小时过期，会触发 del 操作，造成阻塞，而且该操作不会出现在慢查询中(latency 可查)，查找方法和删除方法)

Redis key 命名必须全部小写字母、数字、英文点号(.)和英文半角冒号(:)组成，必须以英文字母开头；

总结：命名规范为 业务模块名：业务逻辑含义：其它: value 类型

示例：user:uid:1:string(模块可以 MD5)

缓存设计
避免缓存穿透数据库中未查询到的数据，可在 Redis 中设置特殊标识，以避免因缓存中无数据而导致每次请求均到达数据库。缓存穿透的解决方案，有以下两种：

缓存空对象：代码维护较简单，但是效果不好。

布隆过滤器：代码维护复杂，效果很好。

避免缓存雪崩当大量缓存集中在某一个时间段失效，这样在失效的时候也会给数据库带来很大压力。对于缓存雪崩的解决方案有以下两种：

搭建高可用的集群，防止单机的 redis 宕机。

设置不同的过期时间，防止同一时间内大量的 key 失效。

避免缓存击穿某个 key 的缓存过期后，同一时间内有大量的请求均访问该 key，由于缓存过期，大量的请求均会访问数据库，并重建缓存；重建缓存的过程加锁，保证只有一个人执行，其他人等待。

可以进行适当的缓存预热对于上线后可能会有大量读请求的应用，在上线之前可预先将数据写入缓存中。

数据一致性问题数据源发生变更时可能导致缓存中数据与数据源中数据不一致，应根据实际业务需求来选择适当的缓存更新策略。

利用 Spring 的事务管理机制，在事务管理器上注册一个事务提交后回调，在回调方法中进行缓存清理；

使用异步消息队列，并且支持重试补偿机制。(如果缓存里数据时间与数据库时间不能匹配，意味着另外一个服务更新了该数据，那么就先从 DB 里读取最新数据版本，然后在新版本上提交数据)

主动更新：在数据源发生变更时同步更新缓存数据或将缓存数据过期。一致性高，维护成本较高。

被动删除：根据缓存设置的过期时间由 Redis 负责数据的过期删除。一致性较低，维护成本较低。

推荐策略：主动更新，数据源发生变更时将缓存数据过期。缓存和 DB 的更新不在同一个事务问题：

业务规范
Redis 应该定位为缓存数据，出特殊需求外，聊天等

Redis 应该设置过期时间，控制 key 的生命周期

Redis 定位为缓存 cache 使用时，对于存放的 key，应该使用 expire 设置过期时间；

若不设置的话，这些 key 会一直占用内存不释放，随着时间的推移会越来越大，直到达到服务器的内存上线，导致党纪等恶劣影响；

对于 key 的超时时长设置，可根据业务需求自行评估，并非越长越好；

某些业务的确需要长期有效，可以在每次设置时，设置超时时间，让超时时间顺延；

Redis 的使用，应该考虑冷热数据分离，不该将所有数据全部放到 Redis 中，对于使用不频繁，且无关的日志等存入 MySQL，或正常的日志文件系统中 Redis 的数据存储全部都是在内存中的，成本昂贵。应该根据业务只将高频热数据存储到 Redis 中，对于低频冷数据可以使用 MySQL/MongoDB 等基于磁盘的存储方式，不仅节省内存成本，而且数据量小在操作时速度更快、效率更高！

Redis 有数据丢失风险，程序处理数据时，应该考虑丢失后的重新加载过程使用 Redis 时，要考虑丢失数据的风险，项目架构时需要考虑到相应解决方案，程序需要处理当 Redis 数据丢失时可进行重新加载。

对于必须要存储的大文本数据应该压缩后存储对于大文本【超过 500 字节】写入到 Redis 时，要压缩后存储。大文本数据存入 Redis，除了带来极大的内存占用外，在访问量高时，很容易就会将网卡流量占满，进而造成整个服务器上的所有服务不可用，并引发雪崩效应，造成各个系统瘫痪！

线上 Redis 一定不可使用 Keys 正则匹配操作

谨慎全量操作 Hash、Set 等集合结构在使用 Hash 结构存储对象属性时，开始只有有限的十几个 field，往往使用 HGETALL 获取所有成员，效率也很高，但是随着业务发展，会将 field 扩张到上百个甚至几百个，此时还使用 HGETALL 会出现效率急剧下降、网卡频繁打满等问题【时间复杂度 O (N)】，此时建议根据业务拆分为多个 Hash 结构；或者如果大部分都是获取所有属性的操作，可以将所有属性序列化为一个 string 类型存储，同样在使用 smembers 操作 set 结构类型时也是相同的情况。

选择合适的数据类型

目前 Redis 支持的数据库结构类型较多：字符串(String)，哈希(Hash)，列表(List)，集合(Set)，有序集合(Sorted Set)，Bitmap，HyperLogLog 和地理空间索引(geospatial)等；

在不能确定其它复杂数据结构一定优于 String 类型时，避免使用 Redis 的复杂数据结构；

每种数据结构都有相应的使用场景，String 类型是 Redis 中最简单的数据类型，建议使用 String 类型；

但是考虑到具体的业务场景，综合评估性能、存储、网络等方面之后使用适当的数据结构；

需要根据业务场景选择合适的类型，常见的如：String 可以用作普通的 K-V、简单数据类型等；Hash 可以用作对象如商品、经纪人等，包含较多属性的信息；List 可以用作消息队列、医生粉丝 / 关注列表等；Set 可以用作推荐；SortedSet 可以用于排行榜等。

例如：实体类型(要合理控制和使用数据结构内存编码优化配置，例如 ziplist，但也要注意节省内存和性能之间的平衡)

反例：set user:1:name tom
set user:1:age 19
set user:1:favor football

正例：hmset user:1 name tom age 19 favor football

命令使用
线上禁止使用 keys 命令 Redis 是单线程处理，在线上 KEY 数量较多时，操作效率极低【时间复杂度为 O (N)】，该命令一旦执行会严重阻塞线上其它命令的正常请求，而且在高 QPS 情况下会直接造成 Redis 服务崩溃。如果有类似需求，请使用 scan 命令代替。

禁用命令禁止线上使用 flushall、flushdb 等，flushall、flushdb 会清空 redis 数据。通过 redis 的 rename 机制禁掉命令，或者使用 scan 的方式渐进式处理。

O (N) 命令关注 N 的数量例如 hgetall、lrange、smembers、zrange、sinter 等并非不能使用，但是需要明确 N 的值。有遍历的需求可以使用 hscan、sscan、zscan 代替。

合理使用 selectredis 的多数据库较弱，使用数字进行区分，很多客户端支持较差，同时多业务用多数据库实际还是单线程处理，会有干扰。哨兵模式中不建议使用多 db，毕竟集群模式已经不能使用多 db。

使用批量操作提高效率

原生命令是原子操作，pipeline 是非原子操作

pipeline 可以打包不同的命令，原生不支持

pipeline 需要客户端和服务端同时支持

原生命令：如 mset、mget

非原生命令：可以使用 pipline 提高效率

但要注意控制一次批量操作的元素个数(例如 500 以内，实际也和元素字节数有关)

Redis 事务功能较弱，不建议过多使用 Redis 的事务功能较弱(不支持回滚)，而且集群版本(自研和官方)要求一次事务操作的 key 必须在一个 slot 上(可以使用 hashtag 功能解决)

Redis 集群版本在使用 Lua 上有特殊要求

Redis 从 2.6 版本开始引入对 Lua 脚本的支持

所有 key 都应该由 KEYS 数组来传递，redis.call/pcall 里面调用的 redis 命令，key 的位置，必须是 KEYS array，否则直接返回 error-ERR bad lua script for redis cluster, all the keys that the script uses should be passed using the KEYS array

Redis 集群对多 key 操作有限制，要求命令中所有的 key 都属于一个 slot，才可以被执行，否则直接返回 error-ERR eval/evalsha command keys must in same slot

必要情况下使用 monitor 命令时，要注意不要长时间使用，造成缓冲区溢出，尽而内存抖动 monitor 命令在开启的情况下会降低 redis 的吞吐量，根据压测结果大概会降低 redis 50% 的吞吐量，越多客户端开启该命令，吞吐量下降会越多。

客户端使用
避免多个应用使用一个实例避免多个应用使用一个 Redis 实例。不要将不相关的业务数据都放到一个实例中，建议新业务申请新的单独实例。因为 Redis 为单线程处理，独立存储会减少不同业务相互操作的影响，提高请求响应速度；同时也避免单个实例内存数据量膨胀过大，在出现异常情况时可以更快恢复服务，公共数据做服务化。

使用连接池使用带有连接池的数据库，可以有效控制连接，同时提高效率，标准使用方式：// 执行命令如下：

Jedis jedis = null;try {   jedis = jedisPool.getResource();   // 具体的命令   jedis.executeCommand()} catch (Exception e) {   logger.error("op key {} error: " + e.getMessage(), key, e);} finally {   // 注意这里不是关闭连接，在 JedisPool 模式下，Jedis 会被归还给资源池。   if (jedis != null)   jedis.close();}
根据业务类型选择淘汰策略根据自身业务类型，选好 maxmemory-policy(最大内存淘汰策略)，设置好过期时间。默认策略是 volatile-lru，即超过最大内存后，在过期键中使用 LRU 算法进行 key 的剔除，保证不过期数据不被删除，但是可能会出现 OOM 问题。其它策略如下：- allkeys-lru：根据 LRU 算法删除键，不管数据有没有设置超时属性，直到腾出足够空间为止。
- allkeys-random：随机删除所有键，直到腾出足够空间为止。
- volatile-random：随机删除过期键，直到腾出足够空间为止。
- volatile-ttl：根据键值对象的 TTL 属性，删除最近将要过期数据。如果没有，回退到 noeviction 策略。
- noeviction：不会剔除任何数据，拒绝所有写入操作并返回客户端错误信息 “(error) OOM command not allowd when used memory”，此时 Redis 只响应操作。如：new JedisPoll (config,new URI ("http://redis:password@host:port/database")); 设置连接池，如果非默认 database，直接在 URI 中指定，不能每次查询 selectDB

设置合理的密码设置合理的密码，如有必要可以使用 SSL 加密访问(阿里云 Redis 支持)

添加熔断功能高并发下建议客户端添加熔断功能(例如 Netflix hystrix)

相关工具推荐
数据同步 Redis 间数据同步可以使用：redis-port

big key 搜索对于 Redis 主从版本可以通过 scan 命令进行扫描，对于集群版本提供了 ISCAN 命令进行扫描，命令规则如下，其中节点个数 node 可以通过 info 命令来获取到。

热点 key 寻找内部实现使用 monitor，所以建议短时间使用，生产环境一般不建议使用，Facebook 的 redis-faina，阿里云 Redis 已经在内核层面解决热点 key 问题。

删除 bigkey
下面操作可以使用 pipeline 加速。

redis 4.0 已经支持 key 的异步删除。

Hash 删除：hscan + hdel

public void delBigHash (String host,int port, String password, String        bigHashKey) {            Jedis jedis = new Jedis(host, port);            if (password != null && !"".equals(password)) {                jedis.auth(password);            }            ScanParams scanParams = new ScanParams().count(100);            String cursor = "0";            do {                ScanResultString,                         cursor, scanParams);                ListString,                 if (entryList != null && !entryList.isEmpty()) {                    for (Entry<String, String> entry : entryList) {                        jedis.hdel(bigHashKey, entry.getKey());                    }                }                cursor = scanResult.getStringCursor();            } while (!"0".equals(cursor));            //删除bigkey            jedis.del(bigHashKey);}
List 删除：ltrim

public void delBigList(String host, int port, String password, StringbigListKey) {  Jedis jedis = new Jedis(host, port);  if (password != null && !"".equals(password)) {    jedis.auth(password);  }  long llen = jedis.llen(bigListKey);  int counter = 0;  int left = 100;  while (counter < llen) {    //每次从左侧截掉100个    jedis.ltrim(bigListKey, left, llen);    counter += left;  }  //最终删除key  jedis.del(bigListKey);}
Set 删除：sscan + srem

public void delBigSet(String host, int port, String password, String bigSetKey) {            Jedis jedis = new Jedis(host, port);            if (password != null && !"".equals(password)) {                jedis.auth(password);            }            ScanParams scanParams = new ScanParams().count(100);            String cursor = "0";            do {                ScanResult<String> scanResult = jedis.sscan(bigSetKey, cursor,                        scanParams);                List<String> memberList = scanResult.getResult();                if (memberList != null && !memberList.isEmpty()) {                    for (String member : memberList) {                        jedis.srem(bigSetKey, member);                    }                }                cursor = scanResult.getStringCursor();            } while (!"0".equals(cursor));            //删除bigkey            jedis.del(bigSetKey);}
SortedSet 删除：zscan + zrem

public void delBigZset(String host, int port, String password, String        bigZsetKey) {    Jedis jedis = new Jedis(host, port);    if (password != null && !"".equals(password)) {        jedis.auth(password);    }    ScanParams scanParams = new ScanParams().count(100);    String cursor = "0";    do {        ScanResult scanResult = jedis.zscan(bigZsetKey, cursor,                scanParams);        List tupleList = scanResult.getResult();        if (tupleList != null && !tupleList.isEmpty()) {            for (Tuple tuple : tupleList) {                jedis.zrem(bigZsetKey, tuple.getElement());            }        }        cursor = scanResult.getStringCursor();    } while (!"0".equals(cursor));    //删除bigkey    jedis.del(bigZsetKey);}
能愿动词介绍
必须(MUST)：绝对，严格遵循，请照做，无条件遵守；

一定不可(MUST NOT)：禁令，严令禁止；

应该(SHOULD)：强烈建议这样做，但是不强求；

不该(SHOULD NOT)：强烈不建议这样做，但是不强求；

可以(MAY)和可选(OPTIONAL)：选择性高一点；
————————————————
版权声明：本文为CSDN博主「weixin_39877898」的原创文章，遵循CC 4.0 BY-SA版权协议，转载请附上原文出处链接及本声明。
原文链接：https://blog.csdn.net/weixin_39877898/article/details/111296023