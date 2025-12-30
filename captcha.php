<?php
session_start();

/**
 * PHP实现简易汉字验证码的思路
 */
// 创建画布
$image = imagecreatetruecolor(108, 35);
$background = imagecolorallocate($image, 255, 255, 255);
imagefill($image, 0, 0, $background);

// 画干扰点
// for ($i = 0; $i < 150; $i++) {
//     $pixColor = imagecolorallocate($image, rand(0, 150), rand(0, 150), rand(0, 150));
//     $pixX = rand(0, 107);
//     $pixY = rand(0, 34);
//     imagesetpixel($image, $pixX, $pixY, $pixColor);
// }

// 画彩色干扰线
for ($i = 0; $i < 10; $i++) {
    $lineColor = imagecolorallocate($image, rand(0, 255), rand(0, 255), rand(0, 255));
    $lineX1 = rand(0, 108);
    $lineY1 = rand(0, 35);
    $lineX2 = rand(0, 108);
    $lineY2 = rand(0, 35);
    imageline($image, $lineX1, $lineY1, $lineX2, $lineY2, $lineColor);
}

// 生成汉字验证码
$text = getChar(4);
$_SESSION['captcha'] = implode('', $text); // 存储验证码到会话中

// 控制最大字符宽度，计算字符占用的空间
for ($i = 0; $i < 4; $i++) {
    $textColor = imagecolorallocate($image, rand(20, 100), rand(20, 100), rand(20, 100));

    // 随机字体大小(16-18)，以避免字体过大导致超出画布
    $fontSize = rand(12, 20);
    $angle = rand(-20, 20); // 控制倾斜角度不太大
    $textX = $i * 25 + 5;   // 保证字符在X轴不会超出
    $textY = rand(22, 30);  // 保证字符在Y轴范围内

    // 添加字符到图像
    imagettftext($image, $fontSize, $angle, $textX, $textY, $textColor, "fzxy.ttf", $text[$i]);
}

// 输出图像
header("Content-Type: image/png");
imagepng($image);
// 销毁图像
imagedestroy($image);

/**
 * 生成指定数量的随机汉字
 *
 * @param int $num 生成汉字的数量
 * @return array 随机汉字数组
 */
function getChar($num)
{
    $b = [];
    for ($i = 0; $i < $num; $i++) {
        // 使用chr()函数拼接双字节汉字，前一个chr()为高位字节，后一个为低位字节
        $a = chr(mt_rand(0xB0, 0xD0)) . chr(mt_rand(0xA1, 0xF0));
        // 转码
        $b[] = iconv('GB2312', 'UTF-8', $a);
    }
    return $b;
}
