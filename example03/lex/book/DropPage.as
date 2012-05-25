package lex.book
{
	import flash.display.*;
	import flash.filters.DropShadowFilter;
	import flash.filters.GlowFilter;
	import flash.geom.*;
	import lex.geom.V2;
	
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class DropPage
	{
		private var pageConer:V2;
		
		public var leftTr:Boolean;
		
		private var dropPageSp:Sprite;
		private var contentDropUsers:Sprite;
		private var maskDropSp:Sprite;
		
		private var doublePageSp:Sprite;
		private var contentDoubleUsers:Sprite;
		private var maskDoubleSp:Sprite;
		
		private var bottomPageSp:Sprite;
		private var maskBottomSp:Sprite;
		
		
		private var contentBottomUsers:Sprite;
		
		public var masterSp:Sprite;
		
		public var bendLine:BendLine;
		public var s:BookStyle;
		public var fixMouse:FixMouse;
		
		
		private var glShade:GlareShade;
		
		
		public function DropPage(s:BookStyle) 
		{
			masterSp = new Sprite();
			
			bottomPageSp = new Sprite();
			maskBottomSp = new Sprite();
			bottomPageSp.addChild(maskBottomSp);
			bottomPageSp.mask = maskBottomSp;
			
			maskDropSp = new Sprite();
			dropPageSp = new Sprite();
			doublePageSp = new Sprite();
			maskDoubleSp = new Sprite();
			
			dropPageSp.addChild(maskDropSp);
			dropPageSp.mask = maskDropSp;
			
			doublePageSp.addChild(maskDoubleSp);
			doublePageSp.mask = maskDoubleSp;
			this.s = s;
			bendLine = new BendLine(s);
			pageConer = new V2();
			fixMouse = new FixMouse(s);
			
			masterSp.addChild(bottomPageSp);
			
			
			masterSp.addChild(doublePageSp);
			
			
			masterSp.addChild(dropPageSp);
			
			
			glShade = new GlareShade(this);
			dropPageSp.addChild(glShade.glareSp);
			dropPageSp.filters = [new GlowFilter(0x000000,0.5,6,6,2,1)];
			masterSp.addChild(glShade.shadeSp);
		}

		public function dropShow(tr:Boolean, maskReset:Boolean = false):void {
			dropPageSp.visible = tr;
			glShade.glareSp.visible = tr;
			glShade.shadeSp.visible = tr;
			
			if (maskReset){
				maskDoubleSp.graphics.beginFill(0);
				maskDoubleSp.graphics.drawRect( -s.width, 0, s.width * 2, s.height);
				
				maskDropSp.graphics.clear();
			}
		}

		public function resetContentBottom(sp:Sprite):void {
			if (contentBottomUsers!=null) if (bottomPageSp.contains(contentBottomUsers)) bottomPageSp.removeChild(contentBottomUsers);
			contentBottomUsers = sp;
			bottomPageSp.addChild(contentBottomUsers);
		}

		public function resetContentDouble(sp:Sprite):void {
			if (contentDoubleUsers!=null) if (doublePageSp.contains(contentDoubleUsers)) doublePageSp.removeChild(contentDoubleUsers);
			contentDoubleUsers = sp;
			doublePageSp.addChild(contentDoubleUsers);
		}

		public function resetContentDrop(sp:Sprite):void {
			if (contentDropUsers!=null) if (dropPageSp.contains(contentDropUsers)) dropPageSp.removeChild(contentDropUsers);
			contentDropUsers = sp;
			dropPageSp.addChildAt(contentDropUsers,0);
		}

		public function action(pageConerX:Number,pageConerY:Number, noDrop:Boolean = false):void {
			//расчет линии перегиба страницы
			//здесь +5 и -5 нужно для старта,что бы страница не дергалась в начале
			var min:Number = 1;
			pageConer.x = pageConerX;
			pageConer.y = pageConerY;
			
			
			pageConer = fixMouse.fix(pageConer);
			
			if (pageConer.distance(s.bl) < min) {
				if (leftTr) pageConer.x += min; 
			} else
			if (pageConer.distance(s.br) < min) if (!leftTr) pageConer.x -= min;
			
			
			
			if (leftTr) bendLine.calcBendLine(pageConer, s.bl); else
				bendLine.calcBendLine(pageConer, s.br);
			
			recaclBottomPageSpMask();
			recalcDoublePageMaskSp();
			recalcDropMask();
			glShade.updateGlare();
			//trace('dropPage action');
			//trace(s.nav.cur);
			if (noDrop) maskDropSp.graphics.clear();
		}

		private function recaclBottomPageSpMask():void {
			
			var gr:Graphics = maskBottomSp.graphics;
			var drLeft:Boolean = true;
			gr.clear();
			
			if (s.nav.cur == s.nav.max && s.nav.aim == 1) return; 
			if (s.nav.cur == 1 && s.nav.aim == s.nav.max) return; 
			
			gr.beginFill(0);
			
			if (!leftTr) {
				//trace('s.nav.aim ' + s.nav.aim);
				
				if (s.nav.cur == s.nav.max - 1) drLeft = false;
				if (!drLeft) {
					if (s.nav.aim == s.nav.max - 1) drLeft = true;
				}
				if (s.nav.aim==s.nav.max+1) drLeft = false;
				if (drLeft){
					gr.moveTo( s.width, s.height);
					
					gr.lineTo( bendLine.interBottom.x , bendLine.interBottom.y);
					gr.lineTo( bendLine.interTop.x , bendLine.interTop.y)
					
					if (!bendLine.interTop.y > 0) gr.lineTo( s.width , 0);
					
					
					gr.lineTo( s.width, s.height);
				}
			} else {//leftTr
				
				if (s.nav.cur!=2){
					gr.moveTo( -s.width, s.height);
					
					gr.lineTo( bendLine.interBottom.x , bendLine.interBottom.y);
					gr.lineTo( bendLine.interTop.x , bendLine.interTop.y)
					
					if (!bendLine.interTop.y > 0) gr.lineTo( -s.width , 0);
					
					
					gr.lineTo( -s.width, s.height);
				}
				
			}
		}

		public function lastPageAction():void {
			//trace('dropPage lastPageAction');
			maskDropSp.graphics.clear();
			maskDropSp.graphics.beginFill(0);
			maskDropSp.graphics.drawRect( -s.width, 0, s.width, s.height);
			
			maskBottomSp.graphics.clear();
			maskBottomSp.graphics.beginFill(0);
			maskBottomSp.graphics.drawRect( -s.width, 0, s.width, s.height);
			
			maskDoubleSp.graphics.clear();
			maskDoubleSp.graphics.beginFill(0);
			maskDoubleSp.graphics.drawRect( -s.width, 0, s.width, s.height);
		}

		private function recalcDoublePageMaskSp():void {
			//маскa текущего разворота (doublePageSp)
			var gr:Graphics = maskDoubleSp.graphics;
			
			gr.clear();
			
			//if (s.nav.cur == s.nav.max && s.nav.aim == 1) return; 
			//if (s.nav.cur == 1 && s.nav.aim == s.nav.max) return; 
			
			gr.clear();
			gr.beginFill(0);
			
			if (leftTr) {
				
				
				
				gr.moveTo( s.width, s.height); 
				if (s.nav.cur==s.nav.max) gr.moveTo( 0, s.height); 
				
				
				
				if (s.nav.cur != s.nav.max) gr.lineTo( s.width , 0); else
				gr.lineTo( 0 , 0);
				
				if (bendLine.interTop.y > 0) {
					gr.lineTo(-s.width, 0);
					gr.lineTo(-s.width, bendLine.interTop.y);
				}else {
					gr.lineTo((bendLine.interTop.x+s.width)-s.width, 0);
				}
				gr.lineTo((bendLine.interBottom.x+s.width)-s.width, s.height);
					
					
				if (s.nav.cur == s.nav.max) gr.lineTo( 0, s.height); else
				gr.lineTo( s.width, s.height); 
				
				
			} else {//!leftTr
				
				
				
				if (s.nav.cur != 1) 
				gr.moveTo( -s.width, 0); 
				else gr.moveTo( 0, 0);
				
				if (bendLine.interTop.y > 0) {
					gr.lineTo(s.width, 0);
					gr.lineTo(s.width, bendLine.interTop.y);
				}else {
					gr.lineTo(s.width - (s.width - bendLine.interTop.x), 0);
				}
				gr.lineTo(s.width - ( +s.width - bendLine.interBottom.x), s.height);
				
				if (s.nav.cur != 1) 
				gr.lineTo( -s.width, s.height); 
				else gr.lineTo( 0, s.height);
				
			}
		}

		private function recalcDropMask():void {
			var mat:Matrix = dropPageSp.transform.matrix;
			var gr:Graphics = maskDropSp.graphics;
			var lgr:Graphics = gr;
			mat.identity();
			mat.ty -= s.height;
			if (leftTr) {
				mat.tx -= s.width;
				mat.rotate( -Math.atan2(pageConer.x - bendLine.interBottom.x, pageConer.y - bendLine.interBottom.y) + Math.PI/2);
				dropPageSp.transform.matrix = mat;
				contentDropUsers.x = 0;
			} else {
				mat.rotate( -Math.atan2(pageConer.x - bendLine.interBottom.x, pageConer.y - bendLine.interBottom.y) - Math.PI / 2);
				contentDropUsers.x = s.width;
			}
			mat.tx += pageConer.x;
			mat.ty += pageConer.y;
			
			dropPageSp.transform.matrix = mat;
			
			//маскa переворачиваемой страницы
			gr.clear();
			gr.beginFill(0);
			
			if (leftTr) {
				lgr.moveTo( s.width, s.height);
				
				lgr.lineTo( s.width , bendLine.interTop.y);
				
				if (bendLine.interTop.y > 0) {
					lgr.lineTo( s.width - (s.width + bendLine.interBottom.x), s.height);
				}else {
					lgr.lineTo(s.width - (s.width + bendLine.interTop.x), 0);
					lgr.lineTo(s.width - (s.width + bendLine.interBottom.x), s.height);
				}
				lgr.lineTo(s.width, s.height);
			} else {
				lgr.moveTo( 0, s.height);
				lgr.lineTo( 0, bendLine.interTop.y);
				if (bendLine.interTop.y > 0) {
					lgr.lineTo( s.width - bendLine.interBottom.x, s.height);
				}else {
					lgr.lineTo(s.width - bendLine.interTop.x, 0);
					lgr.lineTo(s.width - bendLine.interBottom.x, s.height);
				}
				lgr.lineTo(0, s.height);
			}
			
			
		}

	}

}