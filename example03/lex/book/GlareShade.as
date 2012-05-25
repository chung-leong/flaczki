package lex.book 
{
	
	import flash.display.*;
	import flash.geom.*;
	import lex.geom.V2;
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class GlareShade
	{
		
		private var s:BookStyle;
		private var dropPage:DropPage;
		public var glareSp:Sprite;
		private var glareSh:Shape = new Shape();
		private var delim:Number = 4;
		
		public var shadeSp:Sprite;
		private var shadeSh:Shape = new Shape();
		
		private var shadeMask:Shape;
		
		public var alphaShadeGlareDelimiter:Number = 10;
		
		
		public function GlareShade(dropPage:DropPage) 
		{
			this.dropPage = dropPage;
			this.s = dropPage.s;
			
			
			
			var colors:Array = [0xFFFFFF, 0xFFFFFF, 0xFFFFFF];
			var alphas:Array = [0, 0.7, 0];
			var ratios:Array = [0x00, 0xFF/2,0xFF];
			var matr:Matrix = new Matrix();
			
			//рисовать блик
			glareSp = new Sprite();
			glareSp.addChild(glareSh);
			matr.createGradientBox(s.width/delim, s.width*2, 0, 0, 0);
			glareSh.graphics.beginGradientFill(GradientType.LINEAR, colors, alphas, ratios, matr, SpreadMethod.PAD);
			glareSh.graphics.drawRect(0, -s.width * 0.2, s.width / delim, s.width * 2);
			
			//рисовать тень
			shadeSh = new Shape();
			colors = [0x000000, 0x000000]
			alphas = [0, 1];
			ratios = [0, 0xFF];
			//matr.scale(0.5,1);
			//shadeSh.graphics.lineStyle(0);
			shadeSh.graphics.beginGradientFill(GradientType.LINEAR, colors, alphas, ratios, matr, SpreadMethod.PAD);
			shadeSh.graphics.drawRoundRectComplex(0, 0, s.width / delim / 1.5, 100, 10, 0, 10, 0);
			
			shadeSp = new Sprite();
			shadeSp.addChild(shadeSh);
			
			
			shadeMask = new Shape();
			shadeSp.addChild(shadeMask)
			shadeSh.cacheAsBitmap = true;
			shadeMask.cacheAsBitmap = true;
			shadeSh.mask = shadeMask;
		}


		public function updateGlare():void {
			var myX:Number, myY:Number, ang:Number;
			var d:Number;
			var shadowScale:Number = 1;
			myX = dropPage.bendLine.interTop.x - dropPage.bendLine.interBottom.x;
			myY = dropPage.bendLine.interTop.y - dropPage.bendLine.interBottom.y;
			ang =  Math.atan2(myX, myY) * (180 / Math.PI) + 180//Math.PI/2 - 90
			
			if (dropPage.leftTr) {
				d = calcDistToLine(s.bl, dropPage.bendLine.interTop, dropPage.bendLine.interBottom);
				d = Math.abs(d);
				glareSp.x = - dropPage.bendLine.interTop.x;
				glareSp.y = dropPage.bendLine.interTop.y;// -s.width * 0.2;
				
				if (d < s.width / delim && dropPage.bendLine.interBottom.distance(dropPage.bendLine.interTop)< s.width ) 
				glareSp.x = - dropPage.bendLine.interTop.x-(s.width/delim-d);
				
				glareSp.rotation = ang;
				glareSh.x = 0;
				glareSh.y = 0;
				glareSh.rotation = 0;
				
				//работа с тенью
				shadeSp.rotation = - ang;
				shadeSp.x = s.width * 2 + dropPage.bendLine.interTop.x - s.width * 2;
				
				shadeSp.y = dropPage.bendLine.interTop.y;// -s.width * 0.2;
				shadeSh.x = - s.width / delim / 1.5;
				shadeSh.scaleY = dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/100;
				//shadeSh.scaleY = dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/100;
				shadeSh.scaleX = 1;
				
				if (d < s.width / delim && dropPage.bendLine.interBottom.distance(dropPage.bendLine.interTop) < s.width ) {
					shadowScale = d/(s.width / delim);
					shadeSh.scaleX = shadowScale;
					shadeSh.x = -(s.width / delim / 1.5)*shadowScale;
				}
			} else {
				d = calcDistToLine(s.br, dropPage.bendLine.interTop, dropPage.bendLine.interBottom);
				d = Math.abs(d);
				glareSp.x = - dropPage.bendLine.interTop.x + s.width;
				glareSp.y = dropPage.bendLine.interTop.y;;
				glareSp.rotation = 0;
				
				glareSh.x = -s.width/delim;
				glareSh.y = 0;
				glareSp.rotation = ang;
				
				if (d < s.width / delim && dropPage.bendLine.interBottom.distance(dropPage.bendLine.interTop) < s.width ) 
				glareSh.x = -s.width / delim - ( -s.width / delim + d);
				
				//рфбота с тенью
				shadeSp.rotation = - ang;
				shadeSp.x = s.width * 2 + dropPage.bendLine.interTop.x - s.width * 2;
				
				shadeSp.y = dropPage.bendLine.interTop.y;// -s.width * 0.2;
				shadeSh.x = s.width / delim / 1.5;
				shadeSh.scaleY = dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/100;
				//shadeSh.scaleY = dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/100;
				shadeSh.scaleX = -1;
				
				if (d < s.width / delim && dropPage.bendLine.interBottom.distance(dropPage.bendLine.interTop) < s.width ) {
					shadowScale = d/(s.width / delim);
					shadeSh.scaleX = shadowScale;
					shadeSh.x = (s.width / delim / 1.5) * shadowScale;
					shadeSh.scaleX = -shadeSh.scaleX;
					
				}
				
			}
			shadeSh.alpha = glareSh.alpha = 1;
			var alphaGladeShade:Number = Math.min(Math.max(
			s.tl.distance(dropPage.bendLine.interTop)/s.width*alphaShadeGlareDelimiter,
			s.bl.distance(dropPage.bendLine.interBottom) / s.width * alphaShadeGlareDelimiter),
			Math.max(s.tr.distance(dropPage.bendLine.interTop) / s.width * alphaShadeGlareDelimiter,
			s.br.distance(dropPage.bendLine.interBottom) / s.width * alphaShadeGlareDelimiter));
			
			alphaGladeShade = Math.min(alphaGladeShade,
			Math.max(
			s.midTop.distance(dropPage.bendLine.interTop)/s.width*alphaShadeGlareDelimiter,
			s.midBottom.distance(dropPage.bendLine.interBottom) / s.width * alphaShadeGlareDelimiter)
			);
			
			shadeSh.alpha = glareSh.alpha = alphaGladeShade;
			
			shadeMask.graphics.clear();
			
			var colors:Array = [0xFFFFFF, 0x000000, 0x000000, 0xFFFFFF];
			var alphas:Array = [0, 1, 1, 0];
			var p:uint = 16;
			var ratios:Array = [0x0, 0xff/p ,0xff-0xff/p, 0xFF];
			var matr:Matrix = new Matrix();
			matr.scale(dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/0xFF/6.5,1);
			
			matr.tx = dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom)/2;
			matr.rotate(Math.PI/2);
			
			shadeMask.graphics.beginGradientFill(GradientType.LINEAR, colors, alphas, ratios, matr, SpreadMethod.PAD);
			
			//shadeMask.graphics.beginFill(0)
			
			shadeMask.graphics.drawRect( -s.width / delim / 1.5 * shadowScale, 0,  s.width / delim / 1.5 * shadowScale * 2, dropPage.bendLine.interTop.distance(dropPage.bendLine.interBottom));
			
			
		}
		


		public function calcDistToLine(v:V2, a:V2, b:V2):Number {
			var v0:V2 = new V2;
			var v1:V2 = new V2;
			
			v0.copy(a);
			v0.minus(b);
			
			v1.copy(v);
			v1.minus(b);
			
			v0.rightNormal();
			v0.normalize();
			return v1.dot(v0) / v0.length();
		}
	}

}