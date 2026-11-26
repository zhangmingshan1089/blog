<?php
/**
 * MP4 / MOV / M4V → M4A 音轨提取器（纯 PHP，不依赖 ffmpeg）
 * ------------------------------------------------------------------
 * 只做「拆容器、搬箱子」，不碰音频样本本身：
 *   - 按 stsc/stco/stsz 把音频轨的 sample 原样搬到新的 mdat
 *   - stsd（编码参数）整块复制，所以采样率、声道、码率与原始完全一致
 *   - 不做解码、不做重采样、不做二次压缩 → 有损源（AAC）零损失，
 *     无损源（ALAC / PCM）依旧无损
 *
 * 支持：普通 MP4（moov 在前或在后）、QuickTime MOV、M4V；
 * 不支持：分片 MP4（moof 流式录制文件，如 OBS 直录、部分网页录屏）。
 */

declare(strict_types=1);

final class Mp4Audio
{
    /** @var int[] AAC 采样率索引表 */
    private const SR_TABLE = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];

    /** 浏览器能直接解码的编码（放进 m4a 就能播） */
    private const WEB_OK = ['mp4a', 'alac', 'Opus', 'opus', 'fLaC', 'mp3 ', '.mp3'];

    /** 文件头是不是 ISO BMFF（mp4/mov/m4v） */
    public static function isMp4(string $file): bool
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) return false;
        $h = (string)fread($fh, 12);
        fclose($fh);
        return strlen($h) >= 8 && substr($h, 4, 4) === 'ftyp';
    }

    /**
     * 提取音轨到 $dst（.m4a）
     * @return array{ok:bool,code?:string,msg?:string,codec?:string,sr?:int,ch?:int,kbps?:int,dur?:float,lossless?:bool,from_video?:bool}
     */
    public static function extract(string $src, string $dst): array
    {
        @set_time_limit(0);
        $fsize = (int)@filesize($src);
        if ($fsize < 1024) return self::err('empty', '文件是空的');

        $fh = @fopen($src, 'rb');
        if (!$fh) return self::err('io', '读不了源文件');

        // ---- 1. 扫顶层 box，定位 moov / 判断是否分片 ----
        $pos = 0;
        $moov = null;
        $hasMoof = false;
        while ($pos + 8 <= $fsize) {
            if (fseek($fh, $pos) !== 0) break;
            $h = (string)fread($fh, 8);
            if (strlen($h) < 8) break;
            $size = (int)(unpack('N', substr($h, 0, 4))[1] ?? 0);
            $type = substr($h, 4, 4);
            $hlen = 8;
            if ($size === 1) {
                $ext = (string)fread($fh, 8);
                if (strlen($ext) < 8) break;
                $size = (int)(unpack('J', $ext)[1] ?? 0);
                $hlen = 16;
            } elseif ($size === 0) {
                $size = $fsize - $pos;
            }
            if ($size < $hlen) break;
            if ($type === 'moov') $moov = ['ds' => $pos + $hlen, 'de' => $pos + $size, 'len' => $size - $hlen];
            if ($type === 'moof') $hasMoof = true;
            $pos += $size;
        }

        if ($hasMoof && $moov === null) {
            fclose($fh);
            return self::err('fragmented', '这是分片 MP4（录制工具直出的那种），暂时拆不出音轨');
        }
        if ($moov === null) {
            fclose($fh);
            return self::err('nomoov', '文件结构不完整，找不到 moov');
        }
        if ($moov['len'] > 96 * 1024 * 1024) {
            fclose($fh);
            return self::err('toolarge', 'moov 太大，处理不了');
        }
        if (fseek($fh, $moov['ds']) !== 0) {
            fclose($fh);
            return self::err('io', '读取失败');
        }
        $moovData = (string)fread($fh, $moov['len']);
        if (strlen($moovData) !== $moov['len']) {
            fclose($fh);
            return self::err('truncated', '文件可能没上传完整');
        }

        // ---- 2. 在 moov 里找音频轨 ----
        $moovKids = self::children($moovData, 0, strlen($moovData));
        if (isset($moovKids['mvex'])) {
            fclose($fh);
            return self::err('fragmented', '这是分片 MP4（录制工具直出的那种），暂时拆不出音轨');
        }
        $audioTrak = null;
        $hasVideo = false;
        foreach ($moovKids['trak'] ?? [] as $trak) {
            $tk = self::children($moovData, $trak[0], $trak[1]);
            $mdia = $tk['mdia'][0] ?? null;
            if (!$mdia) continue;
            $mk = self::children($moovData, $mdia[0], $mdia[1]);
            $hdlr = $mk['hdlr'][0] ?? null;
            if (!$hdlr) continue;
            $handler = substr($moovData, $hdlr[0] + 8, 4);
            if ($handler === 'vide') $hasVideo = true;
            if ($handler === 'soun' && $audioTrak === null) {
                $audioTrak = ['kids' => $tk, 'mdia' => $mk, 'mdhd' => $mk['mdhd'][0] ?? null, 'hdlr' => $hdlr];
            }
        }
        if ($audioTrak === null) {
            fclose($fh);
            return self::err('noaudio', $hasVideo ? '这个视频没有声音轨' : '文件里没有音轨');
        }

        // ---- 3. 读 mdhd（时间刻度 / 时长） ----
        $mdhd = $audioTrak['mdhd'];
        if (!$mdhd) {
            fclose($fh);
            return self::err('baddata', '音频轨缺少时长信息');
        }
        $ver = ord($moovData[$mdhd[0]]);
        if ($ver === 1) {
            $timescale = (int)(unpack('N', substr($moovData, $mdhd[0] + 20, 4))[1] ?? 0);
            $duration  = (int)(unpack('J', substr($moovData, $mdhd[0] + 24, 8))[1] ?? 0);
        } else {
            $timescale = (int)(unpack('N', substr($moovData, $mdhd[0] + 12, 4))[1] ?? 0);
            $duration  = (int)(unpack('N', substr($moovData, $mdhd[0] + 16, 4))[1] ?? 0);
        }
        if ($timescale <= 0) $timescale = 44100;

        // ---- 4. 音轨参数（stsd） ----
        $minf = $audioTrak['mdia']['minf'][0] ?? null;
        if (!$minf) {
            fclose($fh);
            return self::err('baddata', '音频轨结构异常');
        }
        $mk = self::children($moovData, $minf[0], $minf[1]);
        $stbl = $mk['stbl'][0] ?? null;
        if (!$stbl) {
            fclose($fh);
            return self::err('baddata', '音频轨缺少 sample table');
        }
        $sk = self::children($moovData, $stbl[0], $stbl[1]);
        $stsd = $sk['stsd'][0] ?? null;
        $stts = $sk['stts'][0] ?? null;
        $stsz = $sk['stsz'][0] ?? null;
        $stsc = $sk['stsc'][0] ?? null;
        $stco = $sk['stco'][0] ?? null;
        $co64 = $sk['co64'][0] ?? null;
        if (!$stsd || !$stts || !$stsz || !$stsc || (!$stco && !$co64)) {
            fclose($fh);
            return self::err('baddata', '音频轨索引不完整');
        }

        $codec = substr($moovData, $stsd[0] + 12, 4);
        $entrySize = (int)(unpack('N', substr($moovData, $stsd[0] + 8, 4))[1] ?? 0);
        $channels = (int)(unpack('n', substr($moovData, $stsd[0] + 32, 2))[1] ?? 0);
        $sampRate = (int)((unpack('N', substr($moovData, $stsd[0] + 40, 4))[1] ?? 0) >> 16);
        $aot = 0;

        // esds → AudioSpecificConfig 更准（HE-AAC 采样率会翻倍）
        $esds = self::findEsds($moovData, $stsd[0] + 8, $stsd[0] + 8 + max(0, $entrySize));
        if ($esds !== null) {
            $asc = self::esdsAsc($moovData, $esds[0], $esds[1]);
            if ($asc !== null && strlen($asc) >= 2) {
                $b0 = ord($asc[0]);
                $b1 = ord($asc[1]);
                $aot = $b0 >> 3;
                $sri = (($b0 & 0x07) << 1) | ($b1 >> 7);
                $cfgCh = ($b1 >> 3) & 0x0F;
                if (isset(self::SR_TABLE[$sri])) $sampRate = self::SR_TABLE[$sri];
                if ($cfgCh > 0) $channels = $cfgCh;
                if ($aot === 5 || $aot === 29) $sampRate *= 2;      // SBR
                if ($aot === 29 && $channels < 2) $channels = 2;    // PS 参数立体声
            }
        }
        if ($sampRate <= 0) $sampRate = $timescale > 0 ? $timescale : 44100;
        if ($channels <= 0) $channels = 2;

        // ---- 5. 展开 sample 索引 ----
        $defaultSize = (int)(unpack('N', substr($moovData, $stsz[0] + 4, 4))[1] ?? 0);
        $sampleCount = (int)(unpack('N', substr($moovData, $stsz[0] + 8, 4))[1] ?? 0);
        if ($sampleCount <= 0 || $sampleCount > 12000000) {
            fclose($fh);
            return self::err('baddata', '音频样本表异常');
        }
        $sizesFlat = null;
        if ($defaultSize === 0) {
            $sizesFlat = substr($moovData, $stsz[0] + 12, $sampleCount * 4);
            if (strlen($sizesFlat) < $sampleCount * 4) {
                fclose($fh);
                return self::err('baddata', '样本表被截断');
            }
        }

        $stscCount = (int)(unpack('N', substr($moovData, $stsc[0] + 4, 4))[1] ?? 0);
        $stscRows = [];
        for ($i = 0; $i < $stscCount; $i++) {
            $o = $stsc[0] + 8 + $i * 12;
            $stscRows[] = [
                (int)(unpack('N', substr($moovData, $o, 4))[1] ?? 0),      // first_chunk (1-based)
                (int)(unpack('N', substr($moovData, $o + 4, 4))[1] ?? 0),  // samples_per_chunk
            ];
        }
        if (!$stscRows) {
            fclose($fh);
            return self::err('baddata', 'chunk 表为空');
        }

        if ($stco) {
            $chunkCount = (int)(unpack('N', substr($moovData, $stco[0] + 4, 4))[1] ?? 0);
            $raw = substr($moovData, $stco[0] + 8, $chunkCount * 4);
            if (strlen($raw) < $chunkCount * 4) {
                fclose($fh);
                return self::err('baddata', 'chunk 偏移表被截断');
            }
            $chunkOffsets = array_values(unpack('N*', $raw));
        } else {
            $chunkCount = (int)(unpack('N', substr($moovData, $co64[0] + 4, 4))[1] ?? 0);
            $raw = substr($moovData, $co64[0] + 8, $chunkCount * 8);
            if (strlen($raw) < $chunkCount * 8) {
                fclose($fh);
                return self::err('baddata', 'chunk 偏移表被截断');
            }
            $chunkOffsets = array_values(unpack('J*', $raw));
        }

        // 逐 chunk 摊平成逐 sample，并记录每个 sample 在原文件中的位置
        $offs = [];
        $sizes = [];
        $si = 0;
        $rowIdx = 0;
        $rows = count($stscRows);
        $totalBytes = 0;
        for ($c = 0; $c < $chunkCount && $si < $sampleCount; $c++) {
            while ($rowIdx + 1 < $rows && $stscRows[$rowIdx + 1][0] <= $c + 1) $rowIdx++;
            $spc = $stscRows[$rowIdx][1];
            $p = (int)$chunkOffsets[$c];
            for ($k = 0; $k < $spc && $si < $sampleCount; $k++) {
                $sz = $defaultSize > 0 ? $defaultSize : (int)(unpack('N', substr($sizesFlat, $si * 4, 4))[1] ?? 0);
                if ($sz <= 0 || $p + $sz > $fsize) {
                    fclose($fh);
                    return self::err('corrupt', '样本指针越界，文件可能损坏');
                }
                $offs[] = $p;
                $sizes[] = $sz;
                $totalBytes += $sz;
                $p += $sz;
                $si++;
            }
        }
        if ($si < $sampleCount) {
            fclose($fh);
            return self::err('corrupt', '样本数量对不上，文件可能损坏');
        }

        // 有些流式录制文件 mdhd 时长为 0，退回用 stts 累加
        if ($duration <= 0) {
            $sttsCount = (int)(unpack('N', substr($moovData, $stts[0] + 4, 4))[1] ?? 0);
            $sum = 0;
            for ($i = 0; $i < $sttsCount && $i < 100000; $i++) {
                $o = $stts[0] + 8 + $i * 8;
                $cnt = (int)(unpack('N', substr($moovData, $o, 4))[1] ?? 0);
                $delta = (int)(unpack('N', substr($moovData, $o + 4, 4))[1] ?? 0);
                $sum += $cnt * $delta;
            }
            $duration = $sum;
        }

        $durSec = $timescale > 0 ? $duration / $timescale : 0.0;
        $kbps = $durSec > 0.5 ? (int)round($totalBytes * 8 / $durSec / 1000) : 0;

        // ---- 6. 组装新 moov ----
        $stsdBox = self::sliceBox($moovData, $stsd[0] - 8, $stsd[0] + ($stsd[2] - 8));
        $sttsBox = self::sliceBox($moovData, $stts[0] - 8, $stts[0] + ($stts[2] - 8));
        $cttsBox = '';
        if (isset($sk['ctts'][0])) {
            $ct = $sk['ctts'][0];
            $cttsBox = self::sliceBox($moovData, $ct[0] - 8, $ct[0] + ($ct[2] - 8));
        }
        $stszBox = self::sliceBox($moovData, $stsz[0] - 8, $stsz[0] + ($stsz[2] - 8));

        // 已有 stsz 且可变长：直接沿用原 box，避免重算
        $stszOut = $stszBox;

        $sttsOut = $sttsBox;

        // dinf：优先复制原文件，没有就造一个自包含的
        $dinfBox = '';
        if (isset($mk['dinf'][0])) {
            $dn = $mk['dinf'][0];
            $dinfBox = self::sliceBox($moovData, $dn[0] - 8, $dn[0] + ($dn[2] - 8));
        } else {
            $dref = self::box('dref', pack('N', 0) . pack('N', 1) . self::box('url ', pack('N', 1)));
            $dinfBox = self::box('dinf', $dref);
        }
        $smhdBox = self::box('smhd', pack('N', 0) . pack('n', 0) . pack('n', 0));

        // edts / elst：保留，但要把 segment_duration 从「原 movie timescale」换算到新的
        // （新文件把 movie timescale 直接设成了音频 timescale，不换算时长会缩成 1/44）
        $oldMovieTs = 1000;
        if (isset($moovKids['mvhd'][0])) {
            $mv = $moovKids['mvhd'][0];
            $t = (int)(unpack('N', substr($moovData, $mv[0] + 12, 4))[1] ?? 0);
            if ($t > 0) $oldMovieTs = $t;
        }
        $edtsBox = '';
        if (isset($audioTrak['kids']['edts'][0])) {
            $ed = $audioTrak['kids']['edts'][0];
            $rawEdts = self::sliceBox($moovData, $ed[0] - 8, $ed[0] + ($ed[2] - 8));
            $edtsBox = self::scaleEdts($rawEdts, $oldMovieTs, $timescale);
        }

        $mdhdBox = self::sliceBox($moovData, $mdhd[0] - 8, $mdhd[0] + ($mdhd[2] - 8));
        $hdlrBox = self::sliceBox($moovData, $audioTrak['hdlr'][0] - 8, $audioTrak['hdlr'][0] + ($audioTrak['hdlr'][2] - 8));

        $durMovie = (int)min(0xFFFFFFFF, $duration);

        // 先按 stco + 8 字节 mdat 头算一次布局，超出 4G 再换 co64
        $build = function (int $base, bool $use64) use ($offs, $sizes, $stsdBox, $sttsOut, $cttsBox, $stszOut, $mdhdBox, $hdlrBox, $dinfBox, $smhdBox, $edtsBox, $timescale, $duration, $durMovie): string {
            $n = count($offs);
            if ($use64) {
                $buf = pack('N', 0) . pack('N', $n);
                $cur = $base;
                foreach ($sizes as $sz) {
                    $buf .= pack('J', $cur);
                    $cur += $sz;
                }
                $stco = self::box('co64', $buf);
            } else {
                $buf = pack('N', 0) . pack('N', $n);
                $cur = $base;
                foreach ($sizes as $sz) {
                    $buf .= pack('N', $cur);
                    $cur += $sz;
                }
                $stco = self::box('stco', $buf);
            }
            $stsc = self::box('stsc', pack('N', 0) . pack('N', 1) . pack('N', 1) . pack('N', 1) . pack('N', 1));
            $stbl = self::box('stbl', $stsdBox . $sttsOut . $cttsBox . $stsc . $stszOut . $stco);
            $minf = self::box('minf', $smhdBox . $dinfBox . $stbl);
            $mdia = self::box('mdia', $mdhdBox . $hdlrBox . $minf);

            $matrix = pack('N', 0x00010000) . pack('N', 0) . pack('N', 0)
                    . pack('N', 0) . pack('N', 0x00010000) . pack('N', 0)
                    . pack('N', 0) . pack('N', 0) . pack('N', 0x40000000);
            $tkhd = self::box('tkhd',
                pack('N', 0x000003) . pack('N', 0) . pack('N', 0)
                . pack('N', 1) . pack('N', 0) . pack('N', $durMovie)
                . str_repeat("\0", 8)
                . pack('n', 0) . pack('n', 0) . pack('n', 0x0100) . pack('n', 0)
                . $matrix . pack('N', 0) . pack('N', 0)
            );
            $trak = self::box('trak', $tkhd . $edtsBox . $mdia);

            $mvhd = self::box('mvhd',
                pack('N', 0) . pack('N', 0) . pack('N', 0)
                . pack('N', $timescale) . pack('N', $durMovie)
                . pack('N', 0x00010000) . pack('n', 0x0100) . str_repeat("\0", 10)
                . $matrix . str_repeat("\0", 24) . pack('N', 2)
            );
            return self::box('moov', $mvhd . $trak);
        };

        $ftyp = self::box('ftyp', 'M4A ' . pack('N', 0) . 'M4A ' . 'mp42' . 'isom' . 'iso2');

        $moovZero = $build(0, false);
        $dataStart = strlen($ftyp) + strlen($moovZero) + 8;
        $use64 = ($dataStart + $totalBytes) > 0xFFFFFFFF;
        if ($use64) {
            $moovZero = $build(0, true);
            $dataStart = strlen($ftyp) + strlen($moovZero) + 16;
        }
        $moovFinal = $build($dataStart, $use64);
        if (strlen($moovFinal) !== strlen($moovZero)) {
            fclose($fh);
            return self::err('baddata', '音轨结构异常，无法重排');
        }

        // ---- 7. 写文件 ----
        $out = @fopen($dst, 'wb');
        if (!$out) {
            fclose($fh);
            return self::err('io', '写不了目标文件，检查目录权限');
        }
        $mdatSize = 8 + $totalBytes;
        if ($use64) {
            fwrite($out, $ftyp);
            fwrite($out, $moovFinal);
            fwrite($out, pack('N', 1) . 'mdat' . pack('J', 16 + $totalBytes));
        } else {
            fwrite($out, $ftyp);
            fwrite($out, $moovFinal);
            fwrite($out, pack('N', $mdatSize) . 'mdat');
        }

        $written = 0;
        $expected = 0;
        $bufSize = 1048576;
        foreach ($offs as $i => $off) {
            $sz = $sizes[$i];
            if ($off !== $expected) {
                if (fseek($fh, $off) !== 0) {
                    fclose($out);
                    fclose($fh);
                    @unlink($dst);
                    return self::err('io', '读取源文件失败');
                }
            }
            $left = $sz;
            while ($left > 0) {
                $chunk = fread($fh, min($bufSize, $left));
                if ($chunk === false || $chunk === '') {
                    fclose($out);
                    fclose($fh);
                    @unlink($dst);
                    return self::err('io', '源文件读取中断');
                }
                fwrite($out, $chunk);
                $left -= strlen($chunk);
                $written += strlen($chunk);
            }
            $expected = $off + $sz;
        }
        fclose($out);
        fclose($fh);

        if ($written !== $totalBytes) {
            @unlink($dst);
            return self::err('io', '写入长度不匹配');
        }
        @chmod($dst, 0644);

        $webOk = in_array($codec, self::WEB_OK, true);
        $lossless = in_array($codec, ['alac', 'fLaC', 'sowt', 'twos', 'lpcm', 'in24', 'in32', 'raw '], true);

        return [
            'ok'        => true,
            'codec'     => self::codecName($codec, $aot),
            'sr'        => $sampRate,
            'ch'        => $channels,
            'kbps'      => $kbps,
            'dur'       => round($durSec, 2),
            'lossless'  => $lossless,
            'from_video'=> $hasVideo,
            'web_ok'    => $webOk,
        ];
    }

    private static function err(string $code, string $msg): array
    {
        return ['ok' => false, 'code' => $code, 'msg' => $msg];
    }

    private static function codecName(string $fourcc, int $aot): string
    {
        switch ($fourcc) {
            case 'mp4a':
                if ($aot === 5) return 'HE-AAC';
                if ($aot === 29) return 'HE-AAC v2';
                if ($aot === 2) return 'AAC-LC';
                return 'AAC';
            case 'alac': return 'ALAC';
            case 'ac-3': return 'AC-3';
            case 'ec-3': return 'E-AC-3';
            case 'Opus':
            case 'opus': return 'Opus';
            case 'fLaC': return 'FLAC';
            case 'sowt':
            case 'twos':
            case 'lpcm':
            case 'in24':
            case 'in32':
            case 'raw ': return 'PCM';
            case '.mp3':
            case 'mp3 ': return 'MP3';
        }
        return trim($fourcc) !== '' ? trim($fourcc) : '未知';
    }

    /**
     * 列出容器内一层的子 box
     * @return array<string,array<int,array{0:int,1:int,2:int}>>  type => [[dataStart, dataEnd, size], ...]
     */
    private static function children(string $d, int $start, int $end): array
    {
        $out = [];
        $p = $start;
        while ($p + 8 <= $end) {
            $size = (int)(unpack('N', substr($d, $p, 4))[1] ?? 0);
            $type = substr($d, $p + 4, 4);
            $hlen = 8;
            if ($size === 1) {
                if ($p + 16 > $end) break;
                $size = (int)(unpack('J', substr($d, $p + 8, 8))[1] ?? 0);
                $hlen = 16;
            } elseif ($size === 0) {
                $size = $end - $p;
            }
            if ($size < $hlen || $p + $size > $end) break;
            $out[$type][] = [$p + $hlen, $p + $size, $size];
            $p += $size;
        }
        return $out;
    }

    /**
     * 在 sample entry 范围内找 esds。
     * 不按固定偏移走：音频 sample entry 后面可能挂 wave 等私有 box，
     * 直接扫描盒子类型更省事，也更能扛非标准封装。
     */
    private static function findEsds(string $d, int $start, int $end): ?array
    {
        $p = max(0, $start);
        $end = min($end, strlen($d));
        while ($p < $end) {
            $q = strpos($d, 'esds', $p);
            if ($q === false || $q + 4 > $end) return null;
            $bs = $q - 4;
            if ($bs >= $start) {
                $size = (int)(unpack('N', substr($d, $bs, 4))[1] ?? 0);
                if ($size >= 12 && $bs + $size <= $end) {
                    return [$q + 4, $bs + $size];
                }
            }
            $p = $q + 4;
        }
        return null;
    }

    private static function sliceBox(string $d, int $start, int $end): string
    {
        if ($start < 0 || $end > strlen($d) || $end <= $start) return '';
        return substr($d, $start, $end - $start);
    }

    /** 把 edts/elst 的 segment_duration 从旧 movie timescale 换算到新 movie timescale */
    private static function scaleEdts(string $edts, int $oldTs, int $newTs): string
    {
        if (strlen($edts) < 24) return '';
        if (substr($edts, 12, 4) !== 'elst') return '';
        $ds = 16;
        $ver = ord($edts[$ds]);
        $cnt = (int)(unpack('N', substr($edts, $ds + 4, 4))[1] ?? 0);
        if ($cnt <= 0 || $cnt > 64) return '';
        $p = $ds + 8;
        $out = pack('N', $ver) . pack('N', $cnt);
        $ratio = $newTs / max(1, $oldTs);
        for ($i = 0; $i < $cnt; $i++) {
            if ($ver === 1) {
                if ($p + 20 > strlen($edts)) return '';
                $segDur = (int)(unpack('J', substr($edts, $p, 8))[1] ?? 0);
                $mtime  = (int)(unpack('j', substr($edts, $p + 8, 8))[1] ?? 0);
                $rate   = (int)(unpack('N', substr($edts, $p + 16, 4))[1] ?? 0x00010000);
                $p += 20;
                $out .= pack('J', (int)round($segDur * $ratio)) . pack('j', $mtime) . pack('N', $rate);
            } else {
                if ($p + 12 > strlen($edts)) return '';
                $segDur = (int)(unpack('N', substr($edts, $p, 4))[1] ?? 0);
                $mtime  = (int)(unpack('l', substr($edts, $p + 4, 4))[1] ?? 0);
                $rate   = (int)(unpack('N', substr($edts, $p + 8, 4))[1] ?? 0x00010000);
                $p += 12;
                $out .= pack('N', (int)min(0xFFFFFFFF, round($segDur * $ratio))) . pack('l', $mtime) . pack('N', $rate);
            }
        }
        return self::box('edts', self::box('elst', $out));
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    /** 从 esds 里挖出 AudioSpecificConfig */
    private static function esdsAsc(string $d, int $start, int $end): ?string
    {
        $p = $start;
        while ($p < $end) {
            $tag = ord($d[$p]);
            if ($tag === 0x03 || $tag === 0x04 || $tag === 0x05) {
                $q = $p + 1;
                $len = 0;
                for ($i = 0; $i < 4 && $q < $end; $i++) {
                    $b = ord($d[$q++]);
                    $len = ($len << 7) | ($b & 0x7F);
                    if (!($b & 0x80)) break;
                }
                if ($tag === 0x05) {
                    return substr($d, $q, min($len, $end - $q));
                }
                if ($tag === 0x03) {
                    // ES_ID(2) + flags(1) + 可选字段
                    $q += 3;
                    if ($q <= $end) {
                        $flags = ord($d[$q - 1]);
                        if ($flags & 0x80) $q += 2;
                        if ($flags & 0x40 && $q < $end) {
                            $u = ord($d[$q]);
                            $q += 1 + $u;
                        }
                        if ($flags & 0x20) $q += 2;
                    }
                    $p = $q;
                    continue;
                }
                if ($tag === 0x04) {
                    $p = $q + 13;   // objectType(1) streamType(1) buffer(3) maxBR(4) avgBR(4)
                    continue;
                }
            }
            $p++;
        }
        return null;
    }
}
